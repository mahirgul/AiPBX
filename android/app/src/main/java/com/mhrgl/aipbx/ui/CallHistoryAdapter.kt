package com.mhrgl.aipbx.ui

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.databinding.ItemCallHistoryBinding
import com.mhrgl.aipbx.model.CallRecord

class CallHistoryAdapter(
    private val onCallClick: (String, String?) -> Unit,
    private val onChatClick: (String, String?) -> Unit,
    private val onVideoCallClick: ((String, String?) -> Unit)? = null
) : RecyclerView.Adapter<CallHistoryAdapter.ViewHolder>() {

    private val items = mutableListOf<CallRecord>()

    /**
     * Video calls are on for this user: rows of company extensions get a video
     * button (#83: it was only in the long-press dialog, which users missed).
     */
    var videoEnabled = false
        set(value) {
            if (field != value) {
                field = value
                notifyDataSetChanged()
            }
        }

    /** Extensions of the company directory: only these can be chatted with (not external numbers). */
    var chatExtensions: Set<String> = emptySet()
        set(value) {
            if (field != value) {
                field = value
                notifyDataSetChanged()
            }
        }

    fun submitList(newItems: List<CallRecord>) {
        items.clear()
        items.addAll(newItems)
        notifyDataSetChanged()
    }

    fun itemAt(position: Int): CallRecord? = items.getOrNull(position)

    /** Removes the row at [position] (swipe, #10) and returns it for "Undo". */
    fun removeAt(position: Int): CallRecord? {
        if (position !in items.indices) return null
        val item = items.removeAt(position)
        notifyItemRemoved(position)
        return item
    }

    fun insertAt(position: Int, item: CallRecord) {
        val pos = position.coerceIn(0, items.size)
        items.add(pos, item)
        notifyItemInserted(pos)
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
            val partyName = partyNameStr.ifEmpty { null }
            val canChat = partyStr in chatExtensions
            binding.btnChatHistory.visibility = if (canChat) View.VISIBLE else View.GONE
            binding.btnChatHistory.setOnClickListener { onChatClick(partyStr, partyName) }
            // Video only to company extensions: an external number is reached over a trunk.
            val video = onVideoCallClick.takeIf { videoEnabled && canChat }
            binding.btnVideoHistory.visibility = if (video != null) View.VISIBLE else View.GONE
            binding.btnVideoHistory.setOnClickListener { video?.invoke(partyStr, partyName) }
            binding.btnCallDirect.isEnabled = targetCallParty.isNotEmpty()
            binding.btnCallDirect.setOnClickListener {
                if (targetCallParty.isNotEmpty()) {
                    onCallClick(targetCallParty, partyName)
                }
            }

            // A tap on the row does not call: it asks what to do (as in Contacts).
            itemView.setOnClickListener {
                if (targetCallParty.isEmpty()) return@setOnClickListener
                val actions = mutableListOf<Pair<String, () -> Unit>>(
                    context.getString(R.string.ui_call) to { onCallClick(targetCallParty, partyName) }
                )
                val video = onVideoCallClick.takeIf { videoEnabled }
                if (video != null) actions.add(context.getString(R.string.ui_video_call) to { video(targetCallParty, partyName) })
                if (canChat) actions.add(context.getString(R.string.ui_chat) to { onChatClick(partyStr, partyName) })
                AlertDialog.Builder(context)
                    .setTitle(displayName)
                    .setItems(actions.map { it.first }.toTypedArray()) { _, which -> actions[which].second() }
                    .show()
            }
        }
    }
}
