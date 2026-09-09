package com.mhrgl.aipbx.ui

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.databinding.ItemContactBinding
import com.mhrgl.aipbx.model.ContactItem
import com.mhrgl.aipbx.util.SearchUtils

class ChatContactPickerAdapter(
    private val onContactClick: (ContactItem) -> Unit
) : RecyclerView.Adapter<ChatContactPickerAdapter.ViewHolder>() {

    private val allItems = mutableListOf<ContactItem>()
    private val filteredItems = mutableListOf<ContactItem>()

    fun submitList(items: List<ContactItem>) {
        allItems.clear()
        allItems.addAll(items)
        filteredItems.clear()
        filteredItems.addAll(items)
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
            binding.tvContactExtension.text = "Dahili: ${item.extension}"
            binding.tvContactRole.text = if (item.role.isNullOrEmpty()) "Kurumsal" else item.role

            val initial = item.name.firstOrNull()?.uppercase() ?: item.extension.firstOrNull()?.toString() ?: "?"
            binding.tvContactAvatar.text = initial

            val isOnline = item.status.equals("online", true) ||
                    item.sipStatus.equals("online", true) ||
                    item.webrtcStatus.equals("online", true)
            binding.vContactStatusDot.backgroundTintList = ContextCompat.getColorStateList(
                context,
                if (isOnline) R.color.status_connected else R.color.text_secondary
            )

            binding.btnCallContact.setImageResource(R.drawable.ic_chat)
            binding.btnCallContact.imageTintList = ContextCompat.getColorStateList(context, R.color.primary)
            binding.btnCallContact.contentDescription = "Sohbet Başlat"

            binding.btnCallContact.setOnClickListener { onContactClick(item) }
            itemView.setOnClickListener { onContactClick(item) }
        }
    }
}
