package com.mhrgl.aipbx.service

import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.text.format.DateUtils
import androidx.core.app.NotificationCompat
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.ui.VoicemailActivity
import com.mhrgl.aipbx.util.L10n

/**
 * "New voicemail" notification. It comes over the chat connection while the app
 * is connected and by push otherwise; one fixed id, so the two show as one.
 * Tapping it opens the mailbox.
 */
object VoicemailNotifications {
    private const val NOTIFICATION_ID = 7001

    /** text: the message as text (summary or transcript), shown under the caller when there is one. */
    fun show(context: Context, caller: String, durationSec: Int, newCount: Int, text: String = "") {
        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager ?: return
        val open = PendingIntent.getActivity(
            context, NOTIFICATION_ID,
            Intent(context, VoicemailActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val who = caller.ifBlank { L10n.str(R.string.vm_unknown_caller) }
        val body = L10n.str(R.string.vm_push_body, who, DateUtils.formatElapsedTime(durationSec.toLong()))
        val builder = NotificationCompat.Builder(context, PbxForegroundService.CHANNEL_ID_CHAT)
            .setContentTitle(L10n.str(R.string.vm_title))
            .setContentText(if (text.isNotBlank()) "$body — $text" else body)
        if (text.isNotBlank()) builder.setStyle(NotificationCompat.BigTextStyle().bigText("$body\n$text"))
        val notif = builder
            .setSmallIcon(R.drawable.ic_stat_aipbx)
            .setColor(0xFF0284C7.toInt())
            .setContentIntent(open)
            .setNumber(newCount)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setAutoCancel(true)
            .setDefaults(NotificationCompat.DEFAULT_ALL)
            .build()
        nm.notify(NOTIFICATION_ID, notif)
    }

    fun cancel(context: Context) {
        (context.getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager)?.cancel(NOTIFICATION_ID)
    }
}
