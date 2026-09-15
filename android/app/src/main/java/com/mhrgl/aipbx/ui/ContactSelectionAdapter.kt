package com.mhrgl.aipbx.ui

import android.content.res.ColorStateList
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.CheckBox
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.model.ContactItem
import com.mhrgl.aipbx.util.SearchUtils

class ContactSelectionAdapter(
    private val preSelectedExtensions: Set<String> = emptySet(),
    private val onSelectionChanged: (selectedExtensions: Set<String>) -> Unit
) : RecyclerView.Adapter<ContactSelectionAdapter.ViewHolder>() {

    private val allItems = mutableListOf<ContactItem>()
    private val filteredItems = mutableListOf<ContactItem>()
    private val selectedExtensions = mutableSetOf<String>()

    fun submitList(items: List<ContactItem>) {
        allItems.clear()
        // Do not allow selecting pre-selected items (already members)
        allItems.addAll(items.filter { !preSelectedExtensions.contains(it.extension) })
        filteredItems.clear()
        filteredItems.addAll(allItems)
        notifyDataSetChanged()
    }

    fun filter(query: String) {
        filteredItems.clear()
        val q = query.trim()
        if (q.isEmpty()) {
            filteredItems.addAll(allItems)
        } else {
            for (item in allItems) {
                if (SearchUtils.matches(item.name, q) ||
                    SearchUtils.matches(item.extension, q) ||
                    SearchUtils.matches(item.role, q)
                ) {
                    filteredItems.add(item)
                }
            }
        }
        notifyDataSetChanged()
    }

    fun getSelectedExtensions(): Set<String> = selectedExtensions

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val view = LayoutInflater.from(parent.context)
            .inflate(R.layout.item_contact_checkbox, parent, false)
        return ViewHolder(view)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        holder.bind(filteredItems[position])
    }

    override fun getItemCount(): Int = filteredItems.size

    inner class ViewHolder(itemView: View) : RecyclerView.ViewHolder(itemView) {
        private val cbSelect: CheckBox = itemView.findViewById(R.id.cbSelect)
        private val tvAvatar: TextView = itemView.findViewById(R.id.tvContactAvatar)
        private val vStatusDot: View = itemView.findViewById(R.id.vContactStatusDot)
        private val tvName: TextView = itemView.findViewById(R.id.tvContactName)
        private val tvDetail: TextView = itemView.findViewById(R.id.tvContactDetail)

        fun bind(item: ContactItem) {
            val displayName = item.name ?: "Dahili ${item.extension}"
            tvName.text = displayName

            val roleStr = if (item.role.isNullOrEmpty()) "Kurumsal" else item.role
            tvDetail.text = "Dahili: ${item.extension} • $roleStr"

            val initial = displayName.take(1).uppercase()
            tvAvatar.text = initial

            val isOnline = item.status.equals("online", true) ||
                    item.sipStatus.equals("online", true) ||
                    item.webrtcStatus.equals("online", true)
            vStatusDot.backgroundTintList = ColorStateList.valueOf(
                if (isOnline) 0xFF10B981.toInt() else 0xFF9CA3AF.toInt()
            )

            val isSelected = selectedExtensions.contains(item.extension)
            cbSelect.isChecked = isSelected

            val clickListener = View.OnClickListener {
                if (selectedExtensions.contains(item.extension)) {
                    selectedExtensions.remove(item.extension)
                    cbSelect.isChecked = false
                } else {
                    selectedExtensions.add(item.extension)
                    cbSelect.isChecked = true
                }
                onSelectionChanged(selectedExtensions)
            }

            itemView.setOnClickListener(clickListener)
            cbSelect.setOnClickListener(clickListener)
        }
    }
}
