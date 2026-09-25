package com.mhrgl.aipbx.ui

import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.view.View
import androidx.appcompat.app.AppCompatActivity
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
        super.onCreate(savedInstanceState)
        prefs = AppPreferences.getInstance(this)

        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Edge-to-edge WindowInsets desteği (M22)
        androidx.core.view.ViewCompat.setOnApplyWindowInsetsListener(binding.root) { view, insets ->
            val systemBars = insets.getInsets(androidx.core.view.WindowInsetsCompat.Type.systemBars())
            view.setPadding(systemBars.left, systemBars.top, systemBars.right, systemBars.bottom)
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
                val serverUrl = json.optString("server")
                val qrToken = json.optString("qr_token")
                if (serverUrl.isNotEmpty() && qrToken.isNotEmpty()) {
                    performQrLogin(serverUrl, qrToken)
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

    private fun performQrLogin(serverUrl: String, qrToken: String) {
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
        if (uri.scheme == "aipbx" && uri.host == "auth") {
            val success = uri.getQueryParameter("success") == "1"
            if (success) {
                val dataJson = uri.getQueryParameter("data")
                if (!dataJson.isNullOrEmpty()) {
                    try {
                        val response = com.google.gson.Gson().fromJson(dataJson, com.mhrgl.aipbx.model.LoginResponse::class.java)
                        onLoginSuccess(response)
                        return
                    } catch (e: Exception) {
                        showError("Giriş verisi çözümlenemedi: ${e.message}")
                    }
                }
            } else {
                val error = uri.getQueryParameter("error") ?: "Google ile giriş başarısız oldu."
                showError(error)
            }
        }
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
