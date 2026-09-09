package com.mhrgl.aipbx.ui

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.databinding.ItemCallHistoryBinding
import com.mhrgl.aipbx.model.CallRecord

class CallHistoryAdapter(
    private val onCallClick: (String, String?) -> Unit
) : RecyclerView.Adapter<CallHistoryAdapter.ViewHolder>() {

    private val items = mutableListOf<CallRecord>()

    fun submitList(newItems: List<CallRecord>) {
        items.clear()
        items.addAll(newItems)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemCallHistoryBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        holder.bind(items[position])
    }

    override fun getItemCount(): Int = items.size

    inner class ViewHolder(private val binding: ItemCallHistoryBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: CallRecord) {
            val context = itemView.context

            // Party display
            val partyStr = item.party.trim()
            val partyNameStr = item.partyName?.trim() ?: ""
            val displayName = when {
                partyNameStr.isNotEmpty() && partyStr.isNotEmpty() -> "$partyStr ($partyNameStr)"
                partyStr.isNotEmpty() -> partyStr
                partyNameStr.isNotEmpty() -> partyNameStr
                else -> context.getString(R.string.unknown_number)
            }
            binding.tvCallParty.text = displayName

            // Date formatting
            try {
                val inputFormat = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.getDefault())
                val date = inputFormat.parse(item.calldate)
                if (date != null) {
                    val nowCal = java.util.Calendar.getInstance()
                    val dateCal = java.util.Calendar.getInstance().apply { time = date }
                    val isToday = nowCal.get(java.util.Calendar.YEAR) == dateCal.get(java.util.Calendar.YEAR) &&
                            nowCal.get(java.util.Calendar.DAY_OF_YEAR) == dateCal.get(java.util.Calendar.DAY_OF_YEAR)
                    val isYesterday = nowCal.get(java.util.Calendar.YEAR) == dateCal.get(java.util.Calendar.YEAR) &&
                            nowCal.get(java.util.Calendar.DAY_OF_YEAR) - dateCal.get(java.util.Calendar.DAY_OF_YEAR) == 1

                    val timeStr = java.text.SimpleDateFormat("HH:mm", java.util.Locale.getDefault()).format(date)
                    binding.tvCallDate.text = when {
                        isToday -> context.getString(R.string.date_today, timeStr)
                        isYesterday -> context.getString(R.string.date_yesterday, timeStr)
                        else -> java.text.SimpleDateFormat("dd.MM.yyyy HH:mm", java.util.Locale.getDefault()).format(date)
                    }
                } else {
                    binding.tvCallDate.text = item.calldate
                }
            } catch (e: Exception) {
                binding.tvCallDate.text = item.calldate
            }

            // Direction & Duration
            when (item.direction.lowercase()) {
                "missed" -> {
                    binding.ivCallDirection.setImageResource(R.drawable.ic_call_missed)
                    binding.ivCallDirection.setColorFilter(ContextCompat.getColor(context, R.color.hangup_red))
                    binding.tvCallDuration.text = context.getString(R.string.call_missed_status)
                    binding.tvCallDuration.setTextColor(ContextCompat.getColor(context, R.color.hangup_red))
                }
                "in" -> {
                    binding.ivCallDirection.setImageResource(R.drawable.ic_call_incoming)
                    binding.ivCallDirection.setColorFilter(ContextCompat.getColor(context, R.color.call_green))
                    val mins = item.billsec / 60
                    val secs = item.billsec % 60
                    binding.tvCallDuration.text = String.format("%02d:%02d", mins, secs)
                    binding.tvCallDuration.setTextColor(ContextCompat.getColor(context, R.color.text_secondary))
                }
                else -> { // "out"
                    binding.ivCallDirection.setImageResource(R.drawable.ic_call_outgoing)
                    binding.ivCallDirection.setColorFilter(ContextCompat.getColor(context, R.color.primary))
                    val mins = item.billsec / 60
                    val secs = item.billsec % 60
                    binding.tvCallDuration.text = String.format("%02d:%02d", mins, secs)
                    binding.tvCallDuration.setTextColor(ContextCompat.getColor(context, R.color.text_secondary))
                }
            }

            val targetCallParty = partyStr.ifEmpty { partyNameStr }
            binding.btnCallDirect.isEnabled = targetCallParty.isNotEmpty()
            binding.btnCallDirect.setOnClickListener {
                if (targetCallParty.isNotEmpty()) {
                    onCallClick(targetCallParty, partyNameStr.ifEmpty { null })
                }
            }

            itemView.setOnClickListener {
                if (targetCallParty.isNotEmpty()) {
                    onCallClick(targetCallParty, partyNameStr.ifEmpty { null })
                }
            }
        }
    }
}