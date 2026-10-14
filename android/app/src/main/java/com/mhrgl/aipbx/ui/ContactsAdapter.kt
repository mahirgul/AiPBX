package com.mhrgl.aipbx.ui

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.databinding.ItemContactBinding
import com.mhrgl.aipbx.model.ContactItem

import com.mhrgl.aipbx.util.SearchUtils

/**
 * Contacts list. Tapping a row only offers the choices (call or chat); the call
 * and chat buttons on the right act at once. Phone (device) contacts have no
 * PBX chat, so they offer calling only.
 */
class ContactsAdapter(
    private val onCallClick: (String, String) -> Unit,
    private val onChatClick: ((String, String) -> Unit)? = null,
    private val onVideoCallClick: ((String, String) -> Unit)? = null
) : RecyclerView.Adapter<ContactsAdapter.ViewHolder>() {

    private val allItems = mutableListOf<ContactItem>()
    private val filteredItems = mutableListOf<ContactItem>()

    /** Video calls are on for this user (the dialog then offers "Video call"). */
    var videoEnabled = false

    fun submitList(newItems: List<ContactItem>) {
        allItems.clear()
        allItems.addAll(newItems)
        filteredItems.clear()
        filteredItems.addAll(newItems)
        notifyDataSetChanged()
    }

    fun filter(query: String) {
        filteredItems.clear()
        if (query.trim().isEmpty()) {
            filteredItems.addAll(allItems)
        } else {
            for (item in allItems) {
                if (SearchUtils.matches(item.name, query) ||
                    SearchUtils.matches(item.extension, query) ||
                    SearchUtils.matches(item.role, query)
                ) {
                    filteredItems.add(item)
                }
            }
        }
        notifyDataSetChanged()
    }

    /**
     * I-4: dialer unified search — typing searches two sources at once (organisation + device)
     */
    fun filterUnified(
        query: String,
        corporateList: List<ContactItem>,
        deviceList: List<ContactItem>,
        isDeviceSelected: Boolean
    ) {
        filteredItems.clear()
        if (query.trim().isEmpty()) {
            val base = if (isDeviceSelected) deviceList else corporateList
            allItems.clear()
            allItems.addAll(base)
            filteredItems.addAll(base)
        } else {
            val matchingCorp = corporateList.filter {
                SearchUtils.matches(it.name, query) ||
                SearchUtils.matches(it.extension, query) ||
                SearchUtils.matches(it.role, query)
            }
            val matchingDev = deviceList.filter {
                SearchUtils.matches(it.name, query) ||
                SearchUtils.matches(it.extension, query)
            }
            allItems.clear()
            allItems.addAll(corporateList + deviceList)
            filteredItems.addAll(matchingCorp + matchingDev)
        }
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemContactBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        holder.bind(filteredItems[position])
    }

    override fun getItemCount(): Int = filteredItems.size

    inner class ViewHolder(private val binding: ItemContactBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: ContactItem) {
            val context = itemView.context

            binding.tvContactName.text = item.name
            binding.tvContactExtension.text = if (item.role == itemView.context.getString(R.string.contact_role_device)) item.extension else itemView.context.getString(R.string.extension_label_item, item.extension)
            binding.tvContactRole.text = item.roleName?.takeIf { it.isNotEmpty() } ?: item.role?.takeIf { it.isNotEmpty() } ?: itemView.context.getString(R.string.contact_role_company)

            // Avatar initial
            val initial = item.name.firstOrNull()?.uppercase() ?: item.extension.firstOrNull()?.toString() ?: "?"
            binding.tvContactAvatar.text = initial

            // Status indicator
            when (item.status.lowercase()) {
                "online" -> {
                    binding.vContactStatusDot.backgroundTintList = ContextCompat.getColorStateList(context, R.color.status_connected)
                }
                "busy" -> {
                    binding.vContactStatusDot.backgroundTintList = ContextCompat.getColorStateList(context, R.color.hangup_red)
                }
                else -> { // offline
                    binding.vContactStatusDot.backgroundTintList = ContextCompat.getColorStateList(context, R.color.text_secondary)
                }
            }

            val isDevice = item.role == context.getString(R.string.contact_role_device)
            val chat = onChatClick.takeIf { !isDevice }
            binding.btnChatContact.visibility = if (chat != null) View.VISIBLE else View.GONE

            binding.btnCallContact.setOnClickListener {
                onCallClick(item.extension, item.name)
            }
            binding.btnChatContact.setOnClickListener {
                chat?.invoke(item.extension, item.name)
            }

            // A tap on the row does not call: it asks what to do.
            itemView.setOnClickListener {
                val actions = mutableListOf<Pair<String, () -> Unit>>(
                    context.getString(R.string.ui_call) to { onCallClick(item.extension, item.name) }
                )
                val video = onVideoCallClick.takeIf { videoEnabled && !isDevice }
                if (video != null) actions.add(context.getString(R.string.ui_video_call) to { video(item.extension, item.name) })
                if (chat != null) actions.add(context.getString(R.string.ui_chat) to { chat(item.extension, item.name) })
                AlertDialog.Builder(context)
                    .setTitle(item.name.ifEmpty { item.extension })
                    .setItems(actions.map { it.first }.toTypedArray()) { _, which -> actions[which].second() }
                    .show()
            }
        }
    }
}