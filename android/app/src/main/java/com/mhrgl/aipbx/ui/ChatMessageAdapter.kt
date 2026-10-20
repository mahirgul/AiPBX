package com.mhrgl.aipbx.ui

import android.content.Context
import android.content.Intent
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.webkit.MimeTypeMap
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.core.content.FileProvider
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.SimpleImageLoader
import com.mhrgl.aipbx.model.ChatMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.io.FileOutputStream
import java.net.HttpURLConnection
import java.net.URL

class ChatMessageAdapter(
    private val myExtension: String,
    private val baseUrl: String,
    private var isGroup: Boolean = false,
    /** The current session token — for downloading/opening media (a provider, since the token can be refreshed). */
    private val tokenProvider: () -> String? = { null },
    /** Long press on one of your own messages (offers to delete it). */
    private val onOwnMessageLongPress: ((ChatMessage) -> Unit)? = null
) : RecyclerView.Adapter<RecyclerView.ViewHolder>() {

    private val messages = mutableListOf<ChatMessage>()

    companion object {
        private const val TYPE_ME = 1
        private const val TYPE_OTHER = 2
        private const val TYPE_SYSTEM = 3
    }

    fun setIsGroup(group: Boolean) {
        this.isGroup = group
        notifyDataSetChanged()
    }

    fun submitList(list: List<ChatMessage>) {
        messages.clear()
        messages.addAll(list)
        notifyDataSetChanged()
    }

    private var receiptRead = 0L
    private var receiptDelivered = 0L

    private fun statusFor(id: Long): String = when {
        receiptRead > 0 && id <= receiptRead -> "read"
        receiptDelivered > 0 && id <= receiptDelivered -> "delivered"
        else -> "sent"
    }

    /** Applies a "receipts" event to own messages (ids up to read/delivered). */
    fun applyReceipts(readUpto: Long, deliveredUpto: Long) {
        receiptRead = maxOf(receiptRead, readUpto)
        receiptDelivered = maxOf(receiptDelivered, deliveredUpto)
        messages.forEachIndexed { i, m ->
            val mine = m.isMe || m.senderExt == myExtension
            if (!mine || m.id <= 0 || m.msgType == "system") return@forEachIndexed
            val status = statusFor(m.id)
            if (status != m.status && rank(status) > rank(m.status)) {
                m.status = status
                notifyItemChanged(i)
            }
        }
    }

    private fun rank(status: String?): Int = when (status) { "read" -> 2; "delivered" -> 1; else -> 0 }

    /** Shows a message as deleted (after your delete or a "message_deleted" event). */
    fun markDeleted(messageId: Long) {
        val i = messages.indexOfFirst { it.id == messageId }
        if (i < 0 || messages[i].isDeleted) return
        messages[i] = messages[i].copy(isDeleted = true, message = "", attachmentUrl = null, fileName = null, fileSize = 0)
        notifyItemChanged(i)
    }

    fun addMessage(msg: ChatMessage) {
        if (msg.id > 0 && messages.any { it.id == msg.id }) return
        if ((msg.isMe || msg.senderExt == myExtension) && msg.id > 0 && rank(statusFor(msg.id)) > rank(msg.status)) {
            msg.status = statusFor(msg.id)
        }
        messages.add(msg)
        notifyItemInserted(messages.size - 1)
    }

    override fun getItemViewType(position: Int): Int {
        val m = messages[position]
        if (m.msgType == "system") return TYPE_SYSTEM
        val isMe = m.isMe || m.senderExt == myExtension
        return if (isMe) TYPE_ME else TYPE_OTHER
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
        val inflater = LayoutInflater.from(parent.context)
        return when (viewType) {
            TYPE_SYSTEM -> {
                val view = inflater.inflate(R.layout.item_chat_message_system, parent, false)
                SystemViewHolder(view)
            }
            TYPE_ME -> {
                val view = inflater.inflate(R.layout.item_chat_message_me, parent, false)
                MessageViewHolder(view, true)
            }
            else -> {
                val view = inflater.inflate(R.layout.item_chat_message_other, parent, false)
                MessageViewHolder(view, false)
            }
        }
    }

    override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
        val msg = messages[position]
        if (holder is SystemViewHolder) {
            holder.bind(msg)
        } else if (holder is MessageViewHolder) {
            holder.bind(msg)
        }
    }

    override fun getItemCount(): Int = messages.size

    inner class SystemViewHolder(itemView: View) : RecyclerView.ViewHolder(itemView) {
        private val tvSystemMessage: TextView = itemView.findViewById(R.id.tvSystemMessage)

        fun bind(m: ChatMessage) {
            tvSystemMessage.text = m.message ?: ""
        }
    }

    inner class MessageViewHolder(itemView: View, private val isMe: Boolean) : RecyclerView.ViewHolder(itemView) {
        private val tvSenderName: TextView? = itemView.findViewById(R.id.tvSenderName)
        private val tvMessage: TextView = itemView.findViewById(R.id.tvMessage)
        private val tvTime: TextView = itemView.findViewById(R.id.tvTime)
        private val tvStatus: TextView? = itemView.findViewById(R.id.tvStatus)
        private val ivImage: ImageView = itemView.findViewById(R.id.ivImage)
        private val llFileAttachment: LinearLayout = itemView.findViewById(R.id.llFileAttachment)
        private val tvFileName: TextView = itemView.findViewById(R.id.tvFileName)
        private val tvFileSize: TextView = itemView.findViewById(R.id.tvFileSize)

        fun bind(m: ChatMessage) {
            val ctx = itemView.context

            // Sender name in group chat for other users
            if (!isMe && tvSenderName != null) {
                if (isGroup && m.senderName.isNotEmpty()) {
                    tvSenderName.visibility = View.VISIBLE
                    tvSenderName.text = m.senderName
                    tvSenderName.setTextColor(getDeterministicColor(m.senderExt))
                } else {
                    tvSenderName.visibility = View.GONE
                }
            }

            // Long press on your own message offers to delete it.
            if (isMe && !m.isDeleted && m.id > 0 && onOwnMessageLongPress != null) {
                itemView.setOnLongClickListener { onOwnMessageLongPress?.invoke(m); true }
            } else {
                itemView.setOnLongClickListener(null)
                itemView.isLongClickable = false
            }

            if (m.isDeleted) {
                tvMessage.visibility = View.VISIBLE
                tvMessage.text = ctx.getString(R.string.chat_message_deleted)
                tvMessage.setTypeface(null, android.graphics.Typeface.ITALIC)
                tvMessage.alpha = 0.75f
                ivImage.visibility = View.GONE
                ivImage.setOnClickListener(null)
                llFileAttachment.visibility = View.GONE
                llFileAttachment.setOnClickListener(null)
                tvTime.text = formatTime(m.createdAt)
                tvStatus?.visibility = View.GONE
                return
            }
            tvMessage.setTypeface(null, android.graphics.Typeface.NORMAL)
            tvMessage.alpha = 1f
            tvStatus?.visibility = View.VISIBLE

            // Text message
            if (m.message.isNullOrEmpty()) {
                tvMessage.visibility = View.GONE
            } else {
                tvMessage.visibility = View.VISIBLE
                tvMessage.text = m.message
            }

            // Image attachment
            if (m.msgType == "image" && !m.attachmentUrl.isNullOrEmpty()) {
                ivImage.visibility = View.VISIBLE
                val fullImageUrl = resolveMediaUrl(m.attachmentUrl)
                // The list uses the thumbnail the server generated (≈20 KB);
                // otherwise it falls back to the original image.
                SimpleImageLoader.load(resolveMediaUrl(thumbPathFor(m.attachmentUrl)), ivImage, tokenProvider(), fullImageUrl)

                // An in-app full-screen viewer instead of an external browser.
                ivImage.setOnClickListener {
                    ImageViewerDialog.show(ctx, fullImageUrl, tokenProvider())
                }
            } else {
                ivImage.visibility = View.GONE
            }

            // File attachment
            if (m.msgType == "file" && !m.attachmentUrl.isNullOrEmpty()) {
                llFileAttachment.visibility = View.VISIBLE
                tvFileName.text = m.fileName ?: itemView.context.getString(R.string.chat_document)
                tvFileSize.text = formatFileSize(m.fileSize)

                llFileAttachment.setOnClickListener {
                    val fullFileUrl = resolveMediaUrl(m.attachmentUrl)
                    val fileName = m.fileName ?: "document"
                    openSecureAttachment(ctx, fullFileUrl, fileName)
                }
            } else {
                llFileAttachment.visibility = View.GONE
            }

            tvTime.text = formatTime(m.createdAt)
            tvStatus?.let { tv ->
                when (m.status) {
                    "read" -> { tv.text = "✓✓"; tv.setTextColor(0xFF7DD3FC.toInt()) }
                    "delivered" -> { tv.text = "✓✓"; tv.setTextColor(0xFFE0F2FE.toInt()) }
                    else -> { tv.text = "✓"; tv.setTextColor(0xFFE0F2FE.toInt()) }
                }
            }
        }

        private fun getDeterministicColor(ext: String): Int {
            val palette = intArrayOf(
                0xFF2563EB.toInt(), 0xFF7C3AED.toInt(), 0xFFDB2777.toInt(),
                0xFFEA580C.toInt(), 0xFF059669.toInt(), 0xFF0891B2.toInt(),
                0xFF4F46E5.toInt(), 0xFFD97706.toInt()
            )
            var hash = 0
            for (c in ext) {
                hash = (hash * 31 + c.code) and 0x7FFFFFFF
            }
            return palette[hash % palette.size]
        }

        /**
         * Securely downloads chat attachment to local cache using Authorization Bearer token,
         * then opens via FileProvider without leaking session token in intent URL.
         */
        private fun openSecureAttachment(context: Context, fileUrl: String, fileName: String) {
            val token = tokenProvider()
            CoroutineScope(Dispatchers.Main).launch {
                try {
                    val targetFile = withContext(Dispatchers.IO) {
                        val fileHash = (fileUrl.hashCode().toLong() and 0xFFFFFFFFL).toString(16)
                        val safeName = "${fileHash}_" + fileName.replace(Regex("[^a-zA-Z0-9._-]"), "_")
                        val dir = File(context.cacheDir, "attachments").apply { mkdirs() }
                        val file = File(dir, safeName)
                        if (!file.exists() || file.length() == 0L) {
                            val tempFile = File(dir, "${safeName}_${System.currentTimeMillis()}_${java.util.UUID.randomUUID().toString().take(6)}.part")
                            try {
                                val conn = (URL(fileUrl).openConnection() as HttpURLConnection).apply {
                                    connectTimeout = 15000
                                    readTimeout = 30000
                                    instanceFollowRedirects = true
                                    if (!token.isNullOrEmpty()) {
                                        setRequestProperty("Authorization", "Bearer $token")
                                    }
                                }
                                val code = conn.responseCode
                                if (code !in 200..299) {
                                    throw IllegalStateException("HTTP error $code while downloading attachment")
                                }
                                conn.inputStream.use { input ->
                                    FileOutputStream(tempFile).use { output ->
                                        input.copyTo(output)
                                    }
                                }
                                if (tempFile.length() == 0L) {
                                    tempFile.delete()
                                    throw IllegalStateException("Downloaded file is empty")
                                }
                                if (!tempFile.renameTo(file)) {
                                    tempFile.copyTo(file, overwrite = true)
                                    tempFile.delete()
                                }
                            } catch (e: Exception) {
                                tempFile.delete()
                                throw e
                            }
                        }
                        file
                    }
                    val uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", targetFile)
                    val ext = MimeTypeMap.getFileExtensionFromUrl(targetFile.name)
                    val mime = if (!ext.isNullOrEmpty()) MimeTypeMap.getSingleton().getMimeTypeFromExtension(ext.lowercase()) else null
                    val intent = Intent(Intent.ACTION_VIEW).apply {
                        setDataAndType(uri, mime ?: "*/*")
                        addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    }
                    context.startActivity(intent)
                } catch (e: Exception) {
                    Toast.makeText(context, context.getString(R.string.err_file_read), Toast.LENGTH_SHORT).show()
                }
            }
        }

        /** /chat/media/images/<name>.<ext> → /chat/media/thumbs/<name>_thumb.<ext> (the server's naming). */
        private fun thumbPathFor(path: String): String {
            val marker = "/media/images/"
            val idx = path.indexOf(marker)
            if (idx < 0) return path
            val name = path.substring(idx + marker.length)
            val dot = name.lastIndexOf('.')
            if (dot <= 0) return path
            return path.substring(0, idx) + "/media/thumbs/" + name.substring(0, dot) + "_thumb" + name.substring(dot)
        }

        private fun resolveMediaUrl(path: String): String {
            if (path.startsWith("http://") || path.startsWith("https://")) {
                return path
            }
            val cleanBase = baseUrl.trimEnd('/')
            val cleanPath = "/" + path.trimStart('/')
            return cleanBase + cleanPath
        }

        private fun formatTime(dateStr: String): String {
            return try {
                if (dateStr.length >= 16) {
                    dateStr.substring(11, 16) // HH:mm
                } else {
                    dateStr
                }
            } catch (e: Exception) {
                dateStr
            }
        }

        private fun formatFileSize(bytes: Long): String {
            if (bytes <= 0) return "0 B"
            val kb = bytes / 1024.0
            if (kb < 1024) return String.format("%.1f KB", kb)
            val mb = kb / 1024.0
            return String.format("%.1f MB", mb)
        }
    }
}
