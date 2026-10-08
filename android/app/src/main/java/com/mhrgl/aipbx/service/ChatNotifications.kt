package com.mhrgl.aipbx.service

import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.Person
import androidx.core.app.RemoteInput
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.ui.ChatActivity
import com.mhrgl.aipbx.ui.DialerActivity
import com.mhrgl.aipbx.util.L10n
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * Chat message notifications, for both paths: the push (app asleep) and the
 * live chat connection. Each one carries a "Reply" field (#8): the answer is
 * sent over the chat REST API by [ChatReplyReceiver] without opening the app.
 */
object ChatNotifications {

    const val KEY_REPLY = "chat_reply_text"
    const val EXTRA_SENDER_TITLE = "chat_sender_title"
    const val EXTRA_BODY = "chat_body"

    fun notificationId(convId: Int) = 10000 + (convId % 1000)

    fun show(
        context: Context,
        title: String,
        body: String,
        convId: Int,
        targetExt: String,
        targetName: String?,
        isGroup: Boolean,
        replyText: String? = null,
        replyError: String? = null
    ) {
        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager ?: return

        val openIntent = Intent(context, DialerActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
            putExtra(ChatActivity.EXTRA_CONV_ID, convId)
            putExtra(ChatActivity.EXTRA_TARGET_EXT, targetExt)
            putExtra(ChatActivity.EXTRA_TARGET_NAME, targetName)
            putExtra(ChatActivity.EXTRA_IS_GROUP, isGroup)
        }
        val openPending = PendingIntent.getActivity(
            context, convId, openIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val replyIntent = Intent(context, ChatReplyReceiver::class.java).apply {
            putExtra(ChatActivity.EXTRA_CONV_ID, convId)
            putExtra(ChatActivity.EXTRA_TARGET_EXT, targetExt)
            putExtra(ChatActivity.EXTRA_TARGET_NAME, targetName)
            putExtra(ChatActivity.EXTRA_IS_GROUP, isGroup)
            putExtra(EXTRA_SENDER_TITLE, title)
            putExtra(EXTRA_BODY, body)
        }
        // RemoteInput fills the intent in, so it must be mutable.
        val replyPending = PendingIntent.getBroadcast(
            context, convId, replyIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
        )
        val remoteInput = RemoteInput.Builder(KEY_REPLY)
            .setLabel(L10n.str(R.string.chat_reply_hint))
            .build()
        val replyAction = NotificationCompat.Action.Builder(
            R.drawable.ic_send, L10n.str(R.string.chat_reply_action), replyPending
        )
            .addRemoteInput(remoteInput)
            .setAllowGeneratedReplies(true)
            .setSemanticAction(NotificationCompat.Action.SEMANTIC_ACTION_REPLY)
            .setShowsUserInterface(false)
            .build()

        val me = Person.Builder().setName(L10n.str(R.string.chat_reply_you)).build()
        val sender = Person.Builder().setName(title).build()
        val style = NotificationCompat.MessagingStyle(me)
            .addMessage(body, System.currentTimeMillis(), sender)
        if (replyText != null) {
            style.addMessage(replyText, System.currentTimeMillis(), me)
        }
        if (replyError != null) {
            style.addMessage(L10n.str(R.string.chat_reply_failed, replyError), System.currentTimeMillis(), null as Person?)
        }

        val notif = NotificationCompat.Builder(context, PbxForegroundService.CHANNEL_ID_CHAT)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(style)
            .setSmallIcon(R.drawable.ic_chat)
            .setContentIntent(openPending)
            .addAction(replyAction)
            .setGroup("conv_$convId")
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setAutoCancel(true)
            // The update after a reply must not ring again.
            .setOnlyAlertOnce(replyText != null || replyError != null)
            .apply { if (replyText == null && replyError == null) setDefaults(NotificationCompat.DEFAULT_ALL) }
            .build()

        nm.notify(notificationId(convId), notif)
    }
}

/** Sends the text typed into a chat notification's "Reply" field. */
class ChatReplyReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val text = RemoteInput.getResultsFromIntent(intent)?.getCharSequence(ChatNotifications.KEY_REPLY)
            ?.toString()?.trim().orEmpty()
        val convId = intent.getIntExtra(ChatActivity.EXTRA_CONV_ID, 0)
        if (text.isEmpty() || convId <= 0) return

        val title = intent.getStringExtra(ChatNotifications.EXTRA_SENDER_TITLE).orEmpty()
        val body = intent.getStringExtra(ChatNotifications.EXTRA_BODY).orEmpty()
        val targetExt = intent.getStringExtra(ChatActivity.EXTRA_TARGET_EXT).orEmpty()
        val targetName = intent.getStringExtra(ChatActivity.EXTRA_TARGET_NAME)
        val isGroup = intent.getBooleanExtra(ChatActivity.EXTRA_IS_GROUP, false)

        val prefs = AppPreferences.getInstance(context)
        val token = prefs.token
        val pending = goAsync()
        scope.launch {
            try {
                val result = if (token.isNullOrEmpty()) {
                    Result.failure(Exception(L10n.str(R.string.session_expired_title)))
                } else {
                    val api = ApiClient { prefs }
                    api.sendChatText(prefs.serverUrl, token, convId, text).onSuccess { id ->
                        if (id > 0) api.markChatRead(prefs.serverUrl, token, convId, id)
                    }
                }
                result.onSuccess {
                    ChatNotifications.show(context, title, body, convId, targetExt, targetName, isGroup, replyText = text)
                }.onFailure { e ->
                    Log.w(TAG, "Notification reply failed for conversation $convId", e)
                    ChatNotifications.show(context, title, body, convId, targetExt, targetName, isGroup,
                        replyError = e.message ?: "")
                }
            } finally {
                pending.finish()
            }
        }
    }

    companion object {
        private const val TAG = "ChatReplyReceiver"
        private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    }
}
