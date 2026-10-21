package com.mhrgl.aipbx.ui

import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.os.Bundle
import android.text.format.DateUtils
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.databinding.ActivityVoicemailBinding
import com.mhrgl.aipbx.databinding.ItemVoicemailBinding
import com.mhrgl.aipbx.model.VoicemailMessage
import kotlinx.coroutines.launch
import java.io.File
import java.time.OffsetDateTime

/**
 * The user's own voicemail: list, listen (a new message then counts as heard,
 * which also turns off the phones' voicemail lamp) and delete.
 */
class VoicemailActivity : BaseActivity() {

    companion object {
        fun start(context: Context) = context.startActivity(Intent(context, VoicemailActivity::class.java))
    }

    private lateinit var binding: ActivityVoicemailBinding
    private lateinit var prefs: AppPreferences
    private val apiClient by lazy { ApiClient { prefs } }
    private val messages = mutableListOf<VoicemailMessage>()
    private val adapter = Adapter()
    private var player: MediaPlayer? = null
    private var playingId: String? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = AppPreferences.getInstance(this)
        binding = ActivityVoicemailBinding.inflate(layoutInflater)
        setContentView(binding.root)
        applyEdgeToEdgeInsets(binding.root, consumeIme = false)
        binding.btnBack.setOnClickListener { finish() }
        binding.btnRefresh.setOnClickListener { load() }
        binding.rvVoicemail.layoutManager = LinearLayoutManager(this)
        binding.rvVoicemail.adapter = adapter
        com.mhrgl.aipbx.service.VoicemailNotifications.cancel(this)
        load()
    }

    override fun onStop() {
        super.onStop()
        stopPlayback()
    }

    private fun load() {
        val url = prefs.serverUrl
        val token = prefs.token ?: return
        binding.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            apiClient.getVoicemail(url, token)
                .onSuccess { res ->
                    messages.clear()
                    messages.addAll(res.messages.orEmpty())
                    adapter.notifyDataSetChanged()
                }
                .onFailure { Toast.makeText(this@VoicemailActivity, it.message, Toast.LENGTH_LONG).show() }
            binding.progress.visibility = View.GONE
            binding.tvEmpty.visibility = if (messages.isEmpty()) View.VISIBLE else View.GONE
        }
    }

    private fun togglePlay(msg: VoicemailMessage) {
        if (playingId == msg.id) {
            stopPlayback()
            return
        }
        stopPlayback()
        val url = prefs.serverUrl
        val token = prefs.token ?: return
        playingId = msg.id
        adapter.notifyDataSetChanged()
        lifecycleScope.launch {
            val file = File(cacheDir, "voicemail.wav")
            val res = apiClient.downloadVoicemail(url, token, msg.id, file)
            if (playingId != msg.id) return@launch
            if (res.isFailure) {
                playingId = null
                adapter.notifyDataSetChanged()
                Toast.makeText(this@VoicemailActivity, getString(R.string.vm_play_failed), Toast.LENGTH_LONG).show()
                return@launch
            }
            try {
                player = MediaPlayer().apply {
                    setAudioAttributes(AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_MEDIA)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).build())
                    setDataSource(file.absolutePath)
                    setOnCompletionListener { stopPlayback() }
                    prepare()
                    start()
                }
            } catch (e: Exception) {
                stopPlayback()
                Toast.makeText(this@VoicemailActivity, getString(R.string.vm_play_failed), Toast.LENGTH_LONG).show()
                return@launch
            }
            if (msg.isNew) markHeard(msg)
        }
    }

    private fun markHeard(msg: VoicemailMessage) {
        val token = prefs.token ?: return
        lifecycleScope.launch {
            apiClient.voicemailAction(prefs.serverUrl, token, "heard", msg.id).onSuccess { res ->
                val i = messages.indexOfFirst { it.id == msg.id }
                if (i >= 0) {
                    val newId = res.id ?: msg.id
                    messages[i] = msg.copy(id = newId, isNew = false)
                    if (playingId == msg.id) playingId = newId
                    adapter.notifyItemChanged(i)
                }
            }
        }
    }

    private fun stopPlayback() {
        player?.runCatching { stop(); release() }
        player = null
        if (playingId != null) {
            playingId = null
            adapter.notifyDataSetChanged()
        }
    }

    private fun confirmDelete(msg: VoicemailMessage) {
        AlertDialog.Builder(this)
            .setMessage(getString(R.string.vm_delete_confirm))
            .setPositiveButton(getString(R.string.ui_delete)) { _, _ ->
                if (playingId == msg.id) stopPlayback()
                val token = prefs.token ?: return@setPositiveButton
                lifecycleScope.launch {
                    apiClient.voicemailAction(prefs.serverUrl, token, "delete", msg.id)
                        .onSuccess {
                            val i = messages.indexOfFirst { it.id == msg.id }
                            if (i >= 0) {
                                messages.removeAt(i)
                                adapter.notifyItemRemoved(i)
                            }
                            binding.tvEmpty.visibility = if (messages.isEmpty()) View.VISIBLE else View.GONE
                        }
                        .onFailure { Toast.makeText(this@VoicemailActivity, it.message, Toast.LENGTH_LONG).show() }
                }
            }
            .setNegativeButton(getString(R.string.btn_dismiss), null)
            .show()
    }

    private inner class Adapter : RecyclerView.Adapter<Adapter.Holder>() {
        inner class Holder(val b: ItemVoicemailBinding) : RecyclerView.ViewHolder(b.root)

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) =
            Holder(ItemVoicemailBinding.inflate(LayoutInflater.from(parent.context), parent, false))

        override fun getItemCount() = messages.size

        override fun onBindViewHolder(holder: Holder, position: Int) {
            val m = messages[position]
            val b = holder.b
            val name = m.callerName.orEmpty()
            val number = m.callerNumber.orEmpty()
            b.tvCaller.text = when {
                name.isNotEmpty() && number.isNotEmpty() && name != number -> "$name ($number)"
                name.isNotEmpty() -> name
                number.isNotEmpty() && !number.equals("Unknown", true) -> number
                else -> getString(R.string.vm_unknown_caller)
            }
            b.tvNew.visibility = if (m.isNew) View.VISIBLE else View.GONE
            val whenText = m.time?.let {
                runCatching {
                    val ms = OffsetDateTime.parse(it).toInstant().toEpochMilli()
                    DateUtils.formatDateTime(this@VoicemailActivity, ms,
                        DateUtils.FORMAT_SHOW_DATE or DateUtils.FORMAT_SHOW_TIME or DateUtils.FORMAT_ABBREV_MONTH)
                }.getOrNull()
            }.orEmpty()
            val length = DateUtils.formatElapsedTime(m.duration.toLong())
            b.tvDetails.text = if (whenText.isNotEmpty()) "$whenText · $length" else length
            // Voicemail as text: the summary, else the transcript.
            val said = m.summary?.takeIf { it.isNotBlank() } ?: m.transcript.orEmpty()
            b.tvText.visibility = if (said.isBlank()) View.GONE else View.VISIBLE
            b.tvText.text = said
            b.btnPlay.setImageResource(if (playingId == m.id) R.drawable.ic_pause else R.drawable.ic_play)
            b.btnPlay.isEnabled = m.hasAudio
            b.btnPlay.alpha = if (m.hasAudio) 1f else 0.4f
            b.btnPlay.setOnClickListener { togglePlay(m) }
            b.root.setOnClickListener { if (m.hasAudio) togglePlay(m) }
            b.btnDelete.setOnClickListener { confirmDelete(m) }
        }
    }
}
