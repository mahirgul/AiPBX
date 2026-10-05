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

        // Edge-to-edge WindowInsets support
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
                    // Ask when connecting to a server different from the saved one:
                    // a random QR must not connect the app to another PBX.
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
     * @param replaceSession when true, the current session is closed only AFTER
     *   the NEW sign-in succeeds (on a failed sign-in the user keeps their session).
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
        // The same link (e.g. after a screen rotation) must not be handled twice.
        intent.data = null

        when (uri.host) {
            // Invitation email / mobile sign-in page: aipbx://login?server=…&token=…
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
            // Returning from Google sign-in: aipbx://auth?success=1&code=…
            // The server no longer sends the sign-in data (token + SIP password) in the URL;
            // a single-use code is exchanged on the server where we STARTED the sign-in.
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
     * Confirmation before signing in to another server through a link/QR. The
     * server name is shown so a fake link cannot connect the app to an
     * attacker's PBX. If a session is open, the user is told it will be closed.
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
                // When we came through a link while signed in, do not stay on the login screen.
                if (loggedIn) {
                    startActivity(Intent(this, DialerActivity::class.java))
                    finish()
                }
            }
            .show()
    }

    /** The same steps as "Log out" in DialerActivity (order matters: auth is deleted first so the service does not revive itself). */
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

    private fun performLogin(user: String, pass: String, otp: String? = null) {
        binding.progressBar.visibility = View.VISIBLE
        binding.tvError.visibility = View.GONE
        binding.btnLogin.isEnabled = false

        lifecycleScope.launch {
            val result = apiClient.login(prefs.serverUrl, user, pass, otp)
            binding.progressBar.visibility = View.GONE
            binding.btnLogin.isEnabled = true

            result.onSuccess { response ->
                onLoginSuccess(response)
            }.onFailure { error ->
                if (error is com.mhrgl.aipbx.model.OtpRequiredException) {
                    askOtp(user, pass, error.message ?: "")
                } else {
                    showError(error.localizedMessage ?: "Giriş başarısız oldu.")
                }
            }
        }
    }

    /** Accounts with two-step verification: asks for the 6-digit code from the authenticator app. */
    private fun askOtp(user: String, pass: String, message: String) {
        val input = android.widget.EditText(this).apply {
            inputType = android.text.InputType.TYPE_CLASS_NUMBER
            filters = arrayOf(android.text.InputFilter.LengthFilter(6))
            hint = "123456"
        }
        val pad = (20 * resources.displayMetrics.density).toInt()
        val container = android.widget.FrameLayout(this).apply {
            setPadding(pad, pad / 2, pad, 0)
            addView(input)
        }
        AlertDialog.Builder(this)
            .setTitle("İki adımlı doğrulama")
            .setMessage(message)
            .setView(container)
            .setPositiveButton("Giriş") { _, _ ->
                val code = input.text.toString().trim()
                if (code.length == 6) performLogin(user, pass, code) else showError("6 haneli kodu girin.")
            }
            .setNegativeButton("İptal", null)
            .show()
        input.requestFocus()
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
