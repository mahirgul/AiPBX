package com.mhrgl.aipbx.ui

import com.mhrgl.aipbx.R
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
        binding.tvLogCount.text = getString(R.string.msg_logs_collecting_short)

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
            binding.tvLogCount.text = getString(R.string.logs_total, logLines.size)
        } else {
            val filtered = logLines.filter { it.contains(q, ignoreCase = true) }
            binding.tvLogText.text = filtered.joinToString("\n")
            binding.tvLogCount.text = getString(R.string.logs_filtered, filtered.size, logLines.size)
        }
    }

    private fun copyAllLogs() {
        val currentText = binding.tvLogText.text.toString()
        if (currentText.isEmpty()) {
            Toast.makeText(this, getString(R.string.logs_nothing_to_copy), Toast.LENGTH_SHORT).show()
            return
        }

        val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
        val clip = ClipData.newPlainText(getString(R.string.logs_clip_label), currentText)
        clipboard?.setPrimaryClip(clip)
        Toast.makeText(this, getString(R.string.logs_copied), Toast.LENGTH_SHORT).show()
    }

    private fun shareLogs() {
        lifecycleScope.launch {
            Toast.makeText(this@LogViewerActivity, getString(R.string.logs_preparing_file), Toast.LENGTH_SHORT).show()
            val file = AppLogManager.saveLogsToFile(this@LogViewerActivity)
            AppLogManager.shareLogs(this@LogViewerActivity, file)
        }
    }

    private fun saveLogsToDownloads() {
        lifecycleScope.launch {
            Toast.makeText(this@LogViewerActivity, getString(R.string.logs_saving), Toast.LENGTH_SHORT).show()
            val uri = AppLogManager.exportLogsToDownloads(this@LogViewerActivity)
            if (uri != null) {
                Toast.makeText(this@LogViewerActivity, getString(R.string.logs_saved_ok), Toast.LENGTH_LONG).show()
            } else {
                Toast.makeText(this@LogViewerActivity, getString(R.string.logs_save_failed), Toast.LENGTH_SHORT).show()
            }
        }
    }
}
