package com.mhrgl.aipbx.service

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
        Log.i(TAG, "FCM Message received from: ${remoteMessage.from}, data=${remoteMessage.data}")

        val data = remoteMessage.data
        val action = data["action"] ?: "incoming_call"

        when (action) {
            "incoming_call" -> {
                val callerId = data["caller_id"] ?: ""
                val callerName = data["caller_name"] ?: ""
                Log.i(TAG, "Waking up PbxForegroundService for incoming call from $callerId ($callerName)...")

                // Ensure foreground service is running and reconnects SIP to receive the incoming INVITE
                PbxForegroundService.startWithAction(this, PbxForegroundService.ACTION_RECONNECT)
            }
            "test_push" -> {
                val title = data["title"] ?: getString(R.string.app_name)
                val body = data["body"] ?: "AI PBX test bildirimi başarıyla alındı."
                showTestNotification(title, body)
            }
            "new_message" -> {
                val title = data["title"] ?: "Yeni Mesaj"
                val body = data["body"] ?: "Yeni bir mesaj aldınız."
                val convId = data["conversation_id"]?.toIntOrNull() ?: 0
                val senderExt = data["sender_ext"] ?: ""
                val senderName = data["sender_name"] ?: senderExt
                showChatNotification(title, body, convId, senderExt, senderName)
            }
            else -> {
                Log.d(TAG, "Unknown push action: $action, ensuring service is alive")
                PbxForegroundService.startWithAction(this, PbxForegroundService.ACTION_WATCHDOG)
            }
        }
    }

    private fun showChatNotification(title: String, body: String, convId: Int, senderExt: String, senderName: String) {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager ?: return

        val intent = Intent(this, com.mhrgl.aipbx.ui.ChatActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra(com.mhrgl.aipbx.ui.ChatActivity.EXTRA_CONV_ID, convId)
            putExtra(com.mhrgl.aipbx.ui.ChatActivity.EXTRA_TARGET_EXT, senderExt)
            putExtra(com.mhrgl.aipbx.ui.ChatActivity.EXTRA_TARGET_NAME, senderName)
        }
        val pendingIntent = android.app.PendingIntent.getActivity(
            this,
            convId,
            intent,
            android.app.PendingIntent.FLAG_UPDATE_CURRENT or android.app.PendingIntent.FLAG_IMMUTABLE
        )

        val notif = NotificationCompat.Builder(this, PbxForegroundService.CHANNEL_ID_CHAT)
            .setContentTitle(title)
            .setContentText(body)
            .setSmallIcon(R.drawable.ic_chat)
            .setContentIntent(pendingIntent)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setDefaults(NotificationCompat.DEFAULT_ALL)
            .setAutoCancel(true)
            .build()

        nm.notify(10000 + (convId % 1000), notif)
    }

    private fun showTestNotification(title: String, body: String) {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager ?: return
        val notif = NotificationCompat.Builder(this, PbxForegroundService.CHANNEL_ID_SERVICE)
            .setContentTitle(title)
            .setContentText(body)
            .setSmallIcon(R.drawable.ic_phone)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .build()
        nm.notify(9999, notif)
    }

    companion object {
        private const val TAG = "AiPbxFcmService"
    }
}
