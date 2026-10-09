package com.mhrgl.aipbx.service

import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.app.NotificationCompat
import com.mhrgl.aipbx.AiPbxApp
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.data.ChatWebSocketManager
import com.mhrgl.aipbx.ui.LoginActivity
import com.mhrgl.aipbx.util.L10n

/**
 * Signs the app out when the server no longer accepts the saved session (#17): the token
 * expired, the password was reset, or the server's token secret changed. Before this, only
 * the background service noticed it; the screens kept showing an empty call history and
 * empty settings while the app still looked signed in, until the app was reinstalled.
 */
object SessionExpiry {
    private const val TAG = "SessionExpiry"
    private const val NOTIFICATION_ID = 9001

    /** LoginActivity shows the "session expired" message when this extra is set. */
    const val EXTRA_SESSION_EXPIRED = "session_expired"

    fun signOut(context: Context, reason: String?) {
        val appContext = context.applicationContext
        val prefs = AppPreferences.getInstance(appContext)
        // Several requests can fail at once; only the first one signs out.
        synchronized(this) {
            if (!prefs.isLoggedIn) return
            Log.w(TAG, "Session rejected by server, signing out: $reason")
            prefs.clearAuth()
        }
        ChatWebSocketManager.instance.disconnect()
        try {
            appContext.stopService(Intent(appContext, PbxForegroundService::class.java))
        } catch (e: Exception) {
            Log.w(TAG, "Could not stop the service", e)
        }

        val intent = Intent(appContext, LoginActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
            putExtra(EXTRA_SESSION_EXPIRED, true)
        }
        if (AiPbxApp.isInForeground) {
            // The message is shown on the sign-in screen itself: a toast here was
            // replaced by the failing screen's own error toast.
            Handler(Looper.getMainLooper()).post { appContext.startActivity(intent) }
            return
        }

        val pi = PendingIntent.getActivity(appContext, NOTIFICATION_ID, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val notification = NotificationCompat.Builder(appContext, PbxForegroundService.CHANNEL_ID_CHAT)
            .setContentTitle(L10n.str(R.string.session_expired_title))
            .setContentText(L10n.str(R.string.session_expired_text))
            .setSmallIcon(R.drawable.ic_chat)
            .setContentIntent(pi)
            .setAutoCancel(true)
            .build()
        val nm = appContext.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        nm.notify(NOTIFICATION_ID, notification)
    }
}
