package com.mhrgl.aipbx.ui

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.activity.enableEdgeToEdge
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.launch
import com.mhrgl.aipbx.BuildConfig
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.databinding.ActivityServerSetupBinding

class ServerSetupActivity : AppCompatActivity() {

    private lateinit var binding: ActivityServerSetupBinding
    private lateinit var prefs: AppPreferences
    private val apiClient = ApiClient()

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

    private fun showError(msg: String) {
        binding.tvError.text = msg
        binding.tvError.visibility = View.VISIBLE
    }
}
