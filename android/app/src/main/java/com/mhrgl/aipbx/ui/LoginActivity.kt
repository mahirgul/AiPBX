package com.mhrgl.aipbx.ui

import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import com.mhrgl.aipbx.data.ChatWebSocketManager
import androidx.appcompat.app.AlertDialog
import android.os.PowerManager
import android.provider.Settings
import android.view.View
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.launch
import com.mhrgl.aipbx.BuildConfig
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.databinding.ActivityLoginBinding
import com.mhrgl.aipbx.service.FcmHelper
import com.mhrgl.aipbx.service.PbxForegroundService
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import org.json.JSONObject

class LoginActivity : AppCompatActivity() {

    private lateinit var binding: ActivityLoginBinding
    private lateinit var prefs: AppPreferences
    private val apiClient = ApiClient()

    private val qrScanLauncher = registerForActivityResult(ScanContract()) { result ->
        val content = result.contents
        if (!content.isNullOrEmpty()) {
            handleScannedQr(content)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        prefs = AppPreferences.getInstance(this)

        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Edge-to-edge WindowInsets desteği
        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { view, insets ->
            val systemBars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
            )
            val ime = insets.getInsets(WindowInsetsCompat.Type.ime())
            val bottom = maxOf(systemBars.bottom, ime.bottom)
            view.setPadding(systemBars.left, systemBars.top, systemBars.right, bottom)
            insets
        }

        binding.tvCurrentServer.text = prefs.serverUrl
        binding.tvBrandTitle.text = prefs.brandTitle
        binding.tvBrandSubtitle.text = prefs.brandSubtitle

        val pInfo = try {
            packageManager.getPackageInfo(packageName, 0)
        } catch (e: Exception) { null }
        val vName = pInfo?.versionName ?: BuildConfig.VERSION_NAME
        val vCode = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            pInfo?.longVersionCode ?: BuildConfig.VERSION_CODE.toLong()
        } else {
            @Suppress("DEPRECATION")
            (pInfo?.versionCode?.toLong() ?: BuildConfig.VERSION_CODE.toLong())
        }
        binding.tvVersion.text = "AiPBX v$vName (Build $vCode)"

        binding.btnChangeServer.setOnClickListener {
            startActivity(Intent(this, ServerSetupActivity::class.java))
            finish()
        }

        binding.tvPrivacyPolicy.setOnClickListener {
            try {
                val url = getString(R.string.privacy_policy_url)
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
            } catch (e: Exception) {
                // Browser not found
            }
        }

        binding.btnLogin.setOnClickListener {
            val username = binding.etUsername.text.toString().trim()
            val password = binding.etPassword.text.toString().trim()

            if (username.isEmpty() || password.isEmpty()) {
                showError("Lütfen kullanıcı adı ve şifrenizi girin.")
                return@setOnClickListener
            }

            performLogin(username, password)
        }

        binding.btnGoogleLogin.setOnClickListener {
            val serverUrl = prefs.serverUrl.trim().trimEnd('/')
            if (serverUrl.isEmpty()) {
                showError("Lütfen önce sunucu adresini belirleyin.")
                return@setOnClickListener
            }
            try {
                val googleAuthUrl = "$serverUrl/auth/google?mobile=1"
                val intent = Intent(Intent.ACTION_VIEW, Uri.parse(googleAuthUrl))
                startActivity(intent)
            } catch (e: Exception) {
                showError("Tarayıcı açılamadı: ${e.message}")
            }
        }

        binding.btnQrLogin.setOnClickListener {
            val options = ScanOptions().apply {
                setPrompt("AiPBX web ekranındaki (Dahilim) QR kodu kameraya hizalayın")
                setBeepEnabled(true)
                setOrientationLocked(false)
                setBarcodeImageEnabled(false)
                setDesiredBarcodeFormats(ScanOptions.QR_CODE)
            }
            qrScanLauncher.launch(options)
        }

        handleAuthDeepLink(intent)
    }

    private fun handleScannedQr(qrData: String) {
        try {
            val json = JSONObject(qrData)
            val type = json.optString("type")
            if (type == "aipbx_qr_login") {
                val serverUrl = json.optString("server").trim().trimEnd('/')
                val qrToken = json.optString("qr_token")
                if (serverUrl.isNotEmpty() && qrToken.isNotEmpty()) {
                    // Kayıtlı sunucudan farklı bir sunucuya bağlanacaksa sor:
                    // rastgele bir QR uygulamayı başka bir santrale bağlamasın.
                    if (serverUrl.equals(prefs.serverUrl.trim().trimEnd('/'), ignoreCase = true)) {
                        performQrLogin(serverUrl, qrToken)
                    } else {
                        confirmServerThen(serverUrl) { replace -> performQrLogin(serverUrl, qrToken, replace) }
                    }
                } else {
                    showError("QR kod eksik parametre içeriyor.")
                }
            } else {
                showError("Geçersiz veya uyumsuz AiPBX QR kodu!")
            }
        } catch (e: Exception) {
            showError("QR kod çözümlenemedi: ${e.message}")
        }
    }

    /**
     * @param replaceSession true ise mevcut oturum, YENİ giriş başarılı olduktan
     *   sonra kapatılır (başarısız girişte kullanıcı oturumunu kaybetmez).
     */
    private fun performQrLogin(serverUrl: String, qrToken: String, replaceSession: Boolean = false) {
        binding.progressBar.visibility = View.VISIBLE
        binding.tvError.visibility = View.GONE
        binding.btnLogin.isEnabled = false
        binding.btnQrLogin.isEnabled = false

        lifecycleScope.launch {
            val result = apiClient.qrLogin(serverUrl, qrToken)
            binding.progressBar.visibility = View.GONE
            binding.btnLogin.isEnabled = true
            binding.btnQrLogin.isEnabled = true

            result.onSuccess { response ->
                if (replaceSession) signOutCurrentSession()
                prefs.serverUrl = serverUrl
                binding.tvCurrentServer.text = serverUrl
                onLoginSuccess(response)
            }.onFailure { error ->
                showError(error.localizedMessage ?: "QR kod ile giriş başarısız oldu.")
            }
        }
    }

    override fun onNewIntent(intent: Intent?) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleAuthDeepLink(intent)
    }

    private fun handleAuthDeepLink(intent: Intent?) {
        val uri = intent?.data ?: return
        if (uri.scheme != "aipbx") return
        // Aynı bağlantı (ör. ekran döndürme sonrası) ikinci kez işlenmesin.
        intent.data = null

        when (uri.host) {
            // Davet e-postası / mobil giriş sayfası: aipbx://login?server=…&token=…
            "login" -> {
                val serverUrl = uri.getQueryParameter("server")?.trim()?.trimEnd('/') ?: ""
                val token = uri.getQueryParameter("token")?.trim() ?: ""
                val validServer = serverUrl.startsWith("https://", true) || serverUrl.startsWith("http://", true)
                if (!validServer || token.isEmpty()) {
                    showError("Giriş bağlantısı eksik veya bozuk.")
                    return
                }
                confirmServerThen(serverUrl) { replace -> performQrLogin(serverUrl, token, replace) }
            }
            // Google girişi dönüşü: aipbx://auth?success=1&code=…
            // Sunucu artık giriş bilgisini (token + SIP şifresi) URL'de göndermiyor;
            // tek kullanımlık kod, girişi BAŞLATTIĞIMIZ sunucuda değiş tokuş edilir.
            "auth" -> {
                if (uri.getQueryParameter("success") == "1") {
                    val code = uri.getQueryParameter("code")
                    val serverUrl = prefs.serverUrl.trim().trimEnd('/')
                    if (!code.isNullOrEmpty() && serverUrl.isNotEmpty()) {
                        performQrLogin(serverUrl, code)
                    } else {
                        showError("Google girişi tamamlanamadı. Lütfen tekrar deneyin.")
                    }
                } else {
                    showError(uri.getQueryParameter("error") ?: "Google ile giriş başarısız oldu.")
                }
            }
        }
    }

    /**
     * Bağlantı/QR ile başka bir sunucuya giriş öncesi onay. Sahte bir bağlantı
     * uygulamayı saldırganın santraline bağlayamasın diye sunucu adı gösterilir.
     * Oturum açıksa kullanıcıya kapatılacağı söylenir.
     */
    private fun confirmServerThen(serverUrl: String, onConfirmed: (replaceSession: Boolean) -> Unit) {
        val host = Uri.parse(serverUrl).host ?: serverUrl
        val loggedIn = prefs.isLoggedIn
        val message = StringBuilder()
            .append("\"").append(host).append("\" santraline giriş yapılsın mı?\n\n")
            .append("Bu bağlantıyı yalnızca kurumunuzdan gelen bir e-postadan veya kendi ekranınızdaki QR koddan açtıysanız onaylayın.")
        if (loggedIn) {
            message.append("\n\nŞu an dahili ").append(prefs.extension ?: "")
                .append(" ile oturum açık; giriş başarılı olursa bu oturum kapatılacak.")
        }
        AlertDialog.Builder(this)
            .setTitle("Mobil Giriş")
            .setMessage(message.toString())
            .setCancelable(false)
            .setPositiveButton("Giriş Yap") { _, _ -> onConfirmed(loggedIn) }
            .setNegativeButton("İptal") { _, _ ->
                // Oturum açıkken bağlantıyla gelindiyse giriş ekranında kalmasın.
                if (loggedIn) {
                    startActivity(Intent(this, DialerActivity::class.java))
                    finish()
                }
            }
            .show()
    }

    /** DialerActivity'deki "Çıkış Yap" ile aynı adımlar (sıra önemli: önce auth silinir ki servis kendini diriltmesin). */
    private fun signOutCurrentSession() {
        prefs.clearAuth()
        ChatWebSocketManager.instance.disconnect()
        stopService(Intent(this, PbxForegroundService::class.java))
    }

    private fun onLoginSuccess(response: com.mhrgl.aipbx.model.LoginResponse) {
        prefs.saveLogin(response)

        // Initialize FCM if configured on server (runtime dynamic)
        FcmHelper.initIfConfigured(this, response.pushConfig)

        // Request ignore battery optimizations so the app is never killed when screen is off
        requestBatteryExemption()

        // Start Foreground Service
        PbxForegroundService.start(this)

        // Navigate to Dialer
        startActivity(Intent(this, DialerActivity::class.java))
        finish()
    }

    private fun performLogin(user: String, pass: String) {
        binding.progressBar.visibility = View.VISIBLE
        binding.tvError.visibility = View.GONE
        binding.btnLogin.isEnabled = false

        lifecycleScope.launch {
            val result = apiClient.login(prefs.serverUrl, user, pass)
            binding.progressBar.visibility = View.GONE
            binding.btnLogin.isEnabled = true

            result.onSuccess { response ->
                onLoginSuccess(response)
            }.onFailure { error ->
                showError(error.localizedMessage ?: "Giriş başarısız oldu.")
            }
        }
    }

    @SuppressLint("BatteryLife")
    private fun requestBatteryExemption() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            val pm = getSystemService(Context.POWER_SERVICE) as PowerManager
            if (!pm.isIgnoringBatteryOptimizations(packageName)) {
                try {
                    val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                        data = Uri.parse("package:$packageName")
                    }
                    startActivity(intent)
                } catch (e: Exception) {
                    // Ignored if device does not support intent
                }
            }
        }
    }

    private fun showError(msg: String) {
        binding.tvError.text = msg
        binding.tvError.visibility = View.VISIBLE
    }
}
