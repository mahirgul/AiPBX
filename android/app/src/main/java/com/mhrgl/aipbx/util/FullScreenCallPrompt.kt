package com.mhrgl.aipbx.util

import android.app.Activity
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.appcompat.app.AlertDialog
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.AppPreferences

/**
 * Full-screen incoming calls on a locked phone (#7). Since Android 14 the
 * user (or Play) can withdraw "full-screen notifications" from an app; the
 * call then only shows as a small notification and is easy to miss. The app
 * explains it once and links to the system page; afterwards the status and
 * the link are in Settings.
 */
object FullScreenCallPrompt {

    /** True when incoming calls can open full screen (always true before Android 14). */
    fun isAllowed(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE) return true
        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager ?: return true
        return nm.canUseFullScreenIntent()
    }

    /** Only Android 14+ has the switch, older versions need no button. */
    val isRelevant: Boolean get() = Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE

    fun openSettings(activity: Activity) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE) return
        try {
            activity.startActivity(
                Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT)
                    .setData(Uri.parse("package:${activity.packageName}"))
            )
        } catch (e: Exception) {
            // Some vendors lack the direct page: fall back to the app's notification settings.
            try {
                activity.startActivity(
                    Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                        .putExtra(Settings.EXTRA_APP_PACKAGE, activity.packageName)
                )
            } catch (_: Exception) {
            }
        }
    }

    fun showExplanation(activity: Activity) {
        AlertDialog.Builder(activity)
            .setTitle(activity.getString(R.string.full_screen_title))
            .setMessage(activity.getString(R.string.full_screen_msg))
            .setPositiveButton(activity.getString(R.string.btn_open_settings)) { _, _ -> openSettings(activity) }
            .setNegativeButton(activity.getString(R.string.ui_close), null)
            .show()
    }

    /** Explains the missing permission once per installation (not on every start). */
    fun askOnce(activity: Activity) {
        if (isAllowed(activity)) return
        val prefs = AppPreferences.getInstance(activity)
        if (prefs.fullScreenPromptShown) return
        prefs.fullScreenPromptShown = true
        showExplanation(activity)
    }
}
