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
import androidx.activity.enableEdgeToEdge
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.lifecycle.lifecycleScope
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import com.mhrgl.aipbx.BuildConfig
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.databinding.ActivityServerSetupBinding
import com.mhrgl.aipbx.service.FcmHelper
import com.mhrgl.aipbx.service.PbxForegroundService
import kotlinx.coroutines.launch
import org.json.JSONObject

class ServerSetupActivity : AppCompatActivity() {

    private lateinit var binding: ActivityServerSetupBinding
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

        // Check for previous crash log
        checkCrashReport()

        // If user already configured server and is logged in, jump directly to Dialer
        if (prefs.isLoggedIn) {
            startActivity(Intent(this, DialerActivity::class.java))
            finish()
            return
        }

        binding = ActivityServerSetupBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Edge-to-edge WindowInsets desteği
        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { view, insets ->
            val systemBars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
            )
            view.setPadding(systemBars.left, systemBars.top, systemBars.right, systemBars.bottom)
            insets
        }

        binding.btnQrScan.setOnClickListener {
            val options = ScanOptions().apply {
                setPrompt("Web sayfasındaki MyPhone QR kodunu kutu içine hizalayın")
                setBeepEnabled(true)
                setOrientationLocked(false)
                setBarcodeImageEnabled(false)
                setDesiredBarcodeFormats(ScanOptions.QR_CODE)
            }
            qrScanLauncher.launch(options)
        }

        binding.etServerUrl.setText(prefs.serverUrl)

        val pInfo = try {
            packageManager.getPackageInfo(packageName, 0)
        } catch (e: Exception) { null }
        val vName = pInfo?.versionName ?: BuildConfig.VERSION_NAME
        val vCode = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.P) {
            pInfo?.longVersionCode ?: BuildConfig.VERSION_CODE.toLong()
        } else {
            @Suppress("DEPRECATION")
            (pInfo?.versionCode?.toLong() ?: BuildConfig.VERSION_CODE.toLong())
        }
        binding.tvVersion.text = "AiPBX v$vName (Build $vCode)"

        binding.btnConnect.setOnClickListener {
            val url = binding.etServerUrl.text.toString().trim()
            if (!url.startsWith("http://") && !url.startsWith("https://")) {
                showError(getString(R.string.err_invalid_url))
                return@setOnClickListener
            }
            testServerConnection(url)
        }
    }

    private fun testServerConnection(url: String) {
        binding.progressBar.visibility = View.VISIBLE
        binding.tvError.visibility = View.GONE
        binding.btnConnect.isEnabled = false

        lifecycleScope.launch {
            val result = apiClient.ping(url)
            binding.progressBar.visibility = View.GONE
            binding.btnConnect.isEnabled = true

            result.onSuccess { info ->
                prefs.serverUrl = url
                info.brandTitle?.ifEmpty { null }?.let { prefs.brandTitle = it }
                    ?: info.siteTitle?.ifEmpty { null }?.let { prefs.brandTitle = it }
                info.brandSub?.ifEmpty { null }?.let { prefs.brandSubtitle = it }

                // Navigate to Login screen
                val intent = Intent(this@ServerSetupActivity, LoginActivity::class.java)
                startActivity(intent)
                finish()
            }.onFailure { error ->
                showError("${getString(R.string.err_connection_failed)}\n(${error.localizedMessage})")
            }
        }
    }

    private fun checkCrashReport() {
        try {
            val crashFile = java.io.File(filesDir, "last_crash.txt")
            if (crashFile.exists()) {
                val report = crashFile.readText()
                crashFile.delete()
                androidx.appcompat.app.AlertDialog.Builder(this)
                    .setTitle("Hata Raporu (Önceki Çökme)")
                    .setMessage(report)
                    .setPositiveButton("Kapat", null)
                    .show()
            }
        } catch (e: Exception) {
            // Ignored
        }
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
        binding.btnConnect.isEnabled = false
        binding.btnQrScan.isEnabled = false

        lifecycleScope.launch {
            val result = apiClient.qrLogin(serverUrl, qrToken)
            binding.progressBar.visibility = View.GONE
            binding.btnConnect.isEnabled = true
            binding.btnQrScan.isEnabled = true

            result.onSuccess { response ->
                prefs.serverUrl = serverUrl
                onLoginSuccess(response)
            }.onFailure { error ->
                showError(error.localizedMessage ?: "QR kod ile giriş başarısız oldu.")
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

        // Navigate directly to Dialer
        startActivity(Intent(this, DialerActivity::class.java))
        finish()
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
                    // Ignored
                }
            }
        }
    }

    private fun showError(msg: String) {
        binding.tvError.text = msg
        binding.tvError.visibility = View.VISIBLE
    }
}
