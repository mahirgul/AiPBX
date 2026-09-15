package com.mhrgl.aipbx.ui

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.model.ChatConversation

class ChatConversationAdapter(
    private val onConversationClick: (ChatConversation) -> Unit
) : RecyclerView.Adapter<ChatConversationAdapter.ViewHolder>() {

    private val items = mutableListOf<ChatConversation>()

    fun submitList(newList: List<ChatConversation>) {
        items.clear()
        items.addAll(newList)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val view = LayoutInflater.from(parent.context)
            .inflate(R.layout.item_chat_conversation, parent, false)
        return ViewHolder(view)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        holder.bind(items[position])
    }

    override fun getItemCount(): Int = items.size

    inner class ViewHolder(itemView: View) : RecyclerView.ViewHolder(itemView) {
        private val tvAvatar: TextView = itemView.findViewById(R.id.tvAvatar)
        private val vOnlineDot: View = itemView.findViewById(R.id.vOnlineDot)
        private val tvTargetName: TextView = itemView.findViewById(R.id.tvTargetName)
        private val tvGroupTag: TextView = itemView.findViewById(R.id.tvGroupTag)
        private val tvTime: TextView = itemView.findViewById(R.id.tvTime)
        private val tvLastMessage: TextView = itemView.findViewById(R.id.tvLastMessage)
        private val tvUnreadBadge: TextView = itemView.findViewById(R.id.tvUnreadBadge)

        fun bind(item: ChatConversation) {
            val isGroup = item.type == "group"

            if (isGroup) {
                val displayName = item.title ?: "Grup Sohbeti"
                tvTargetName.text = displayName
                tvGroupTag.visibility = View.VISIBLE
                tvAvatar.text = "👥"
                tvAvatar.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF4F46E5.toInt())
                vOnlineDot.visibility = View.GONE
                tvLastMessage.text = item.lastMessageText ?: "${item.memberCount} üye"
            } else {
                val displayName = item.targetName ?: item.targetExt ?: "Kullanıcı"
                tvTargetName.text = displayName
                tvGroupTag.visibility = View.GONE
                tvAvatar.text = displayName.take(1).uppercase()
                tvAvatar.backgroundTintList = null
                vOnlineDot.visibility = View.VISIBLE
                vOnlineDot.backgroundTintList = android.content.res.ColorStateList.valueOf(
                    if (item.targetOnline) 0xFF10B981.toInt() else 0xFF9CA3AF.toInt()
                )
                tvLastMessage.text = item.lastMessageText ?: "Sohbet başlatıldı"
            }

            tvTime.text = formatChatTime(item.lastMessageAt)

            if (item.unreadCount > 0) {
                tvUnreadBadge.visibility = View.VISIBLE
                tvUnreadBadge.text = item.unreadCount.toString()
            } else {
                tvUnreadBadge.visibility = View.GONE
            }

            itemView.setOnClickListener {
                onConversationClick(item)
            }
        }

        private fun formatChatTime(dateStr: String?): String {
            if (dateStr.isNullOrEmpty()) return ""
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
    }
}
