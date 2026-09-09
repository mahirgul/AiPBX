package com.mhrgl.aipbx.ui

import android.graphics.Color
import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.databinding.ItemContactBinding
import com.mhrgl.aipbx.model.ContactItem

import com.mhrgl.aipbx.util.SearchUtils

class ContactsAdapter(
    private val onCallClick: (String, String) -> Unit
) : RecyclerView.Adapter<ContactsAdapter.ViewHolder>() {

    private val allItems = mutableListOf<ContactItem>()
    private val filteredItems = mutableListOf<ContactItem>()

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
     * I-4: Dialer birleşik arama — Arama yazıldığında iki kaynak birden taranır (Kurumsal + Cihaz)
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
            binding.tvContactExtension.text = if (item.role == "Cihaz Rehberi") item.extension else "Dahili: ${item.extension}"
            binding.tvContactRole.text = if (item.role.isNullOrEmpty()) "Kurumsal" else item.role

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

            binding.btnCallContact.setOnClickListener {
                onCallClick(item.extension, item.name)
            }

            itemView.setOnClickListener {
                onCallClick(item.extension, item.name)
            }
        }
    }
}