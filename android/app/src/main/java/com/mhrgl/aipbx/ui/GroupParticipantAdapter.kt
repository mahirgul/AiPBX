package com.mhrgl.aipbx.ui

import android.content.res.ColorStateList
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ImageButton
import android.widget.TextView
import androidx.appcompat.widget.PopupMenu
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.model.ChatParticipant

class GroupParticipantAdapter(
    private val myExtension: String,
    private val isAdmin: Boolean,
    private val onToggleAdminRole: (ChatParticipant) -> Unit,
    private val onRemoveMember: (ChatParticipant) -> Unit
) : RecyclerView.Adapter<GroupParticipantAdapter.ViewHolder>() {

    private val items = mutableListOf<ChatParticipant>()

    fun submitList(newList: List<ChatParticipant>) {
        items.clear()
        items.addAll(newList)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val view = LayoutInflater.from(parent.context)
            .inflate(R.layout.item_group_member, parent, false)
        return ViewHolder(view)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        holder.bind(items[position])
    }

    override fun getItemCount(): Int = items.size

    inner class ViewHolder(itemView: View) : RecyclerView.ViewHolder(itemView) {
        private val tvAvatar: TextView = itemView.findViewById(R.id.tvMemberAvatar)
        private val vStatusDot: View = itemView.findViewById(R.id.vMemberStatusDot)
        private val tvName: TextView = itemView.findViewById(R.id.tvMemberName)
        private val tvExtension: TextView = itemView.findViewById(R.id.tvMemberExtension)
        private val tvRoleBadge: TextView = itemView.findViewById(R.id.tvMemberRoleBadge)
        private val btnAction: ImageButton = itemView.findViewById(R.id.btnMemberAction)

        fun bind(participant: ChatParticipant) {
            val displayName = participant.name ?: "Dahili ${participant.extension}"
            tvName.text = if (participant.extension == myExtension) "$displayName (Sen)" else displayName
            tvExtension.text = "Dahili: ${participant.extension}"

            val initial = displayName.take(1).uppercase()
            tvAvatar.text = initial

            vStatusDot.backgroundTintList = ColorStateList.valueOf(
                if (participant.isOnline) 0xFF10B981.toInt() else 0xFF9CA3AF.toInt()
            )

            if (participant.role.equals("admin", ignoreCase = true)) {
                tvRoleBadge.visibility = View.VISIBLE
                tvRoleBadge.text = "Yönetici"
            } else {
                tvRoleBadge.visibility = View.GONE
            }

            // If current user is admin and this participant is not current user
            if (isAdmin && participant.extension != myExtension) {
                btnAction.visibility = View.VISIBLE
                btnAction.setOnClickListener { view ->
                    val popup = PopupMenu(itemView.context, view)
                    val isMemberAdmin = participant.role.equals("admin", ignoreCase = true)

                    if (isMemberAdmin) {
                        popup.menu.add(0, 1, 0, "Yöneticilikten Çıkar")
                    } else {
                        popup.menu.add(0, 1, 0, "Yönetici Yap")
                    }
                    popup.menu.add(0, 2, 1, "Gruptan Çıkar")

                    popup.setOnMenuItemClickListener { menuItem ->
                        when (menuItem.itemId) {
                            1 -> {
                                onToggleAdminRole(participant)
                                true
                            }
                            2 -> {
                                onRemoveMember(participant)
                                true
                            }
                            else -> false
                        }
                    }
                    popup.show()
                }
            } else {
                btnAction.visibility = View.GONE
            }
        }
    }
}
