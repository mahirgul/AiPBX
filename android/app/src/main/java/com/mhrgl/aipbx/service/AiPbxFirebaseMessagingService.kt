package com.mhrgl.aipbx.service

import com.mhrgl.aipbx.util.L10n
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.AppPreferences

class AiPbxFirebaseMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        Log.i(TAG, "New FCM Token received: ${token.take(15)}...")
        AppPreferences.getInstance(this).fcmToken = token
        FcmHelper.sendTokenToServer(this, token)
    }

    override fun onMessageReceived(remoteMessage: RemoteMessage) {
        super.onMessageReceived(remoteMessage)
        val prefs = AppPreferences.getInstance(this)
        if (!prefs.isLoggedIn) {
            Log.d(TAG, "Ignoring incoming FCM push because user is logged out")
            return
        }
        val data = remoteMessage.data
        val action = data["action"] ?: "incoming_call"
        Log.i(TAG, "FCM Message received from: ${remoteMessage.from}, action=$action")

        when (action) {
            "incoming_call" -> {
                Log.i(TAG, "Waking up PbxForegroundService for incoming call...")

                // Ensure foreground service is running and reconnects SIP to receive the incoming INVITE
                PbxForegroundService.startWithAction(this, PbxForegroundService.ACTION_RECONNECT)
            }
            "voicemail" -> {
                VoicemailNotifications.show(
                    this, data["caller"] ?: "", data["duration"]?.toIntOrNull() ?: 0, data["new_count"]?.toIntOrNull() ?: 1,
                    data["text"] ?: ""
                )
            }
            "test_push" -> {
                val title = data["title"] ?: getString(R.string.app_name)
                val body = data["body"] ?: L10n.str(R.string.push_test_body)
                showTestNotification(title, body)
            }
            "new_message", "group_created", "group_member_added" -> {
                val convId = data["conversation_id"]?.toIntOrNull() ?: 0
                if (convId > 0 && com.mhrgl.aipbx.ui.ChatKeys.activeConversationId == convId) {
                    return
                }
                val title = data["title"] ?: L10n.str(R.string.push_new_message_title)
                val body = data["body"] ?: L10n.str(R.string.push_new_message)
                val convType = data["conversation_type"] ?: "direct"
                val isGroup = convType == "group"
                val senderExt = data["sender_ext"] ?: ""
                val groupTitle = data["group_title"] ?: title
                val targetName = if (isGroup) groupTitle else (data["sender_name"] ?: senderExt)
                val targetExt = if (isGroup) "" else senderExt
                showChatNotification(title, body, convId, targetExt, targetName, isGroup)
            }
            else -> {
                Log.d(TAG, "Unknown push action: $action, ensuring service is alive")
                PbxForegroundService.startWithAction(this, PbxForegroundService.ACTION_WATCHDOG)
            }
        }
    }

    private fun showChatNotification(title: String, body: String, convId: Int, targetExt: String, targetName: String, isGroup: Boolean) {
        ChatNotifications.show(this, title, body, convId, targetExt, targetName, isGroup)
    }

    private fun showTestNotification(title: String, body: String) {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager ?: return
        val notif = NotificationCompat.Builder(this, PbxForegroundService.CHANNEL_ID_SERVICE)
            .setContentTitle(title)
            .setContentText(body)
            .setSmallIcon(R.drawable.ic_stat_aipbx)
            .setColor(0xFF0284C7.toInt())
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .build()
        nm.notify(9999, notif)
    }

    companion object {
        private const val TAG = "AiPbxFcmService"
    }
}
