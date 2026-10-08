package com.mhrgl.aipbx.util

import android.annotation.SuppressLint
import android.app.Activity
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import com.mhrgl.aipbx.data.AppPreferences

/**
 * The system "stop optimising battery usage" request, shown automatically only
 * ONCE per installation. It used to open on every start of the dialer, so a
 * user who declined it (or whose phone does not keep the exemption) was asked
 * every time the app opened. Later it is reachable from Settings → Battery
 * optimisation.
 */
object BatteryPrompt {

    @SuppressLint("BatteryLife")
    /** Returns true when the system request was opened now. */
    fun askOnce(activity: Activity): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return false
        val prefs = AppPreferences.getInstance(activity)
        if (prefs.batteryPromptShown) return false
        val pm = activity.getSystemService(Context.POWER_SERVICE) as? PowerManager ?: return false
        if (pm.isIgnoringBatteryOptimizations(activity.packageName)) return false
        prefs.batteryPromptShown = true
        try {
            activity.startActivity(
                Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS)
                    .setData(Uri.parse("package:${activity.packageName}"))
            )
            return true
        } catch (e: Exception) {
            // The device has no such screen; the Settings button remains.
            return false
        }
    }
}
