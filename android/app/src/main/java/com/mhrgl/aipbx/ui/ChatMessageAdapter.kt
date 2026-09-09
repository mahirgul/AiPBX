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
    private val baseUrl: String
) : RecyclerView.Adapter<ChatMessageAdapter.MessageViewHolder>() {

    private val messages = mutableListOf<ChatMessage>()

    companion object {
        private const val TYPE_ME = 1
        private const val TYPE_OTHER = 2
    }

    fun submitList(list: List<ChatMessage>) {
        messages.clear()
        messages.addAll(list)
        notifyDataSetChanged()
    }

    fun addMessage(msg: ChatMessage) {
        messages.add(msg)
        notifyItemInserted(messages.size - 1)
    }

    override fun getItemViewType(position: Int): Int {
        val m = messages[position]
        val isMe = m.isMe || m.senderExt == myExtension
        return if (isMe) TYPE_ME else TYPE_OTHER
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): MessageViewHolder {
        val layoutRes = if (viewType == TYPE_ME) {
            R.layout.item_chat_message_me
        } else {
            R.layout.item_chat_message_other
        }
        val view = LayoutInflater.from(parent.context).inflate(layoutRes, parent, false)
        return MessageViewHolder(view)
    }

    override fun onBindViewHolder(holder: MessageViewHolder, position: Int) {
        holder.bind(messages[position])
    }

    override fun getItemCount(): Int = messages.size

    inner class MessageViewHolder(itemView: View) : RecyclerView.ViewHolder(itemView) {
        private val tvMessage: TextView = itemView.findViewById(R.id.tvMessage)
        private val tvTime: TextView = itemView.findViewById(R.id.tvTime)
        private val ivImage: ImageView = itemView.findViewById(R.id.ivImage)
        private val llFileAttachment: LinearLayout = itemView.findViewById(R.id.llFileAttachment)
        private val tvFileName: TextView = itemView.findViewById(R.id.tvFileName)
        private val tvFileSize: TextView = itemView.findViewById(R.id.tvFileSize)

        fun bind(m: ChatMessage) {
            val ctx = itemView.context

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
