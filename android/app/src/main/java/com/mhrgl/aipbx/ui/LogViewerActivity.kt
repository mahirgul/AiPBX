package com.mhrgl.aipbx.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.widget.doAfterTextChanged
import androidx.lifecycle.lifecycleScope
import com.mhrgl.aipbx.databinding.ActivityLogViewerBinding
import com.mhrgl.aipbx.util.AppLogManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class LogViewerActivity : AppCompatActivity() {

    companion object {
        fun start(context: Context) {
            val intent = Intent(context, LogViewerActivity::class.java)
            context.startActivity(intent)
        }
    }

    private lateinit var binding: ActivityLogViewerBinding
    private var fullLogText: String = ""
    private var logLines: List<String> = emptyList()

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        binding = ActivityLogViewerBinding.inflate(layoutInflater)
        setContentView(binding.root)

        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { view, insets ->
            val systemBars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
            )
            view.setPadding(systemBars.left, systemBars.top, systemBars.right, systemBars.bottom)
            insets
        }

        setupListeners()
        loadLogs()
    }

    private fun setupListeners() {
        binding.btnBack.setOnClickListener {
            finish()
        }

        binding.btnRefresh.setOnClickListener {
            loadLogs()
        }

        binding.btnCopy.setOnClickListener {
            copyAllLogs()
        }

        binding.btnShare.setOnClickListener {
            shareLogs()
        }

        binding.btnDownloadToFolder.setOnClickListener {
            saveLogsToDownloads()
        }

        binding.etLogSearch.doAfterTextChanged { text ->
            filterLogs(text?.toString() ?: "")
        }
    }

    private fun loadLogs() {
        binding.pbLoading.visibility = View.VISIBLE
        binding.tvLogText.text = ""
        binding.tvLogCount.text = "Loglar toplanıyor..."

        lifecycleScope.launch {
            fullLogText = AppLogManager.collectLogs(this@LogViewerActivity)
            logLines = fullLogText.lines()

            binding.pbLoading.visibility = View.GONE
            filterLogs(binding.etLogSearch.text?.toString() ?: "")
        }
    }

    private fun filterLogs(query: String) {
        val q = query.trim()
        if (q.isEmpty()) {
            binding.tvLogText.text = fullLogText
            binding.tvLogCount.text = "Toplam: ${logLines.size} satır"
        } else {
            val filtered = logLines.filter { it.contains(q, ignoreCase = true) }
            binding.tvLogText.text = filtered.joinToString("\n")
            binding.tvLogCount.text = "Filtrelendi: ${filtered.size} / ${logLines.size} satır"
        }
    }

    private fun copyAllLogs() {
        val currentText = binding.tvLogText.text.toString()
        if (currentText.isEmpty()) {
            Toast.makeText(this, "Kopyalanacak log yok", Toast.LENGTH_SHORT).show()
            return
        }

        val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
        val clip = ClipData.newPlainText("AI PBX Logları", currentText)
        clipboard?.setPrimaryClip(clip)
        Toast.makeText(this, "Loglar panoya kopyalandı", Toast.LENGTH_SHORT).show()
    }

    private fun shareLogs() {
        lifecycleScope.launch {
            Toast.makeText(this@LogViewerActivity, "Log dosyası hazırlanıyor...", Toast.LENGTH_SHORT).show()
            val file = AppLogManager.saveLogsToFile(this@LogViewerActivity)
            AppLogManager.shareLogs(this@LogViewerActivity, file)
        }
    }

    private fun saveLogsToDownloads() {
        lifecycleScope.launch {
            Toast.makeText(this@LogViewerActivity, "Loglar İndirilenler klasörüne kaydediliyor...", Toast.LENGTH_SHORT).show()
            val uri = AppLogManager.exportLogsToDownloads(this@LogViewerActivity)
            if (uri != null) {
                Toast.makeText(this@LogViewerActivity, "Başarıyla İndirilenler/AiPBX klasörüne kaydedildi!", Toast.LENGTH_LONG).show()
            } else {
                Toast.makeText(this@LogViewerActivity, "İndirilenler klasörüne kaydedilemedi", Toast.LENGTH_SHORT).show()
            }
        }
    }
}
