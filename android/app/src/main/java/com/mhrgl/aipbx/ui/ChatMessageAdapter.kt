package com.mhrgl.aipbx.ui

import android.content.Intent
import android.net.Uri
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.SimpleImageLoader
import com.mhrgl.aipbx.model.ChatMessage

class ChatMessageAdapter(
    private val myExtension: String,
    private val baseUrl: String,
    private var isGroup: Boolean = false
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

    fun addMessage(msg: ChatMessage) {
        if (msg.id > 0 && messages.any { it.id == msg.id }) return
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
                SimpleImageLoader.load(fullImageUrl, ivImage)

                ivImage.setOnClickListener {
                    try {
                        val intent = Intent(Intent.ACTION_VIEW, Uri.parse(fullImageUrl))
                        ctx.startActivity(intent)
                    } catch (e: Exception) {}
                }
            } else {
                ivImage.visibility = View.GONE
            }

            // File attachment
            if (m.msgType == "file" && !m.attachmentUrl.isNullOrEmpty()) {
                llFileAttachment.visibility = View.VISIBLE
                tvFileName.text = m.fileName ?: "Belge"
                tvFileSize.text = formatFileSize(m.fileSize)

                llFileAttachment.setOnClickListener {
                    try {
                        val fullFileUrl = resolveMediaUrl(m.attachmentUrl)
                        val intent = Intent(Intent.ACTION_VIEW, Uri.parse(fullFileUrl))
                        ctx.startActivity(intent)
                    } catch (e: Exception) {}
                }
            } else {
                llFileAttachment.visibility = View.GONE
            }

            tvTime.text = formatTime(m.createdAt)
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
