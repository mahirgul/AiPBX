package com.mhrgl.aipbx.service

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.SystemClock
import android.util.Log
import com.mhrgl.aipbx.data.AppPreferences

class ResurrectionReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val prefs = AppPreferences.getInstance(context)
        if (!prefs.isLoggedIn) {
            Log.d(TAG, "User not logged in, cancelling resurrection alarms.")
            cancel(context)
            return
        }

        Log.d(TAG, "Resurrection receiver triggered: action=${intent.action}")

        // I-2: Kendi kendini teşhis (Watchdog / arka plan uyutulma aralığı denetimi)
        val lastTs = prefs.lastResurrectionTimestamp
        val now = System.currentTimeMillis()
        if (lastTs > 0L) {
            val diffMs = now - lastTs
            if (diffMs > 20 * 60 * 1000L) {
                Log.w(TAG, "Gap detected in background execution: ${diffMs / 60000} mins elapsed! System is sleeping app.")
                prefs.hasSleepingWarning = true
            }
        }
        prefs.lastResurrectionTimestamp = now

        if (!PbxForegroundService.isServiceRunning) {
            Log.w(TAG, "PbxForegroundService is not running! Resurrecting service now...")
            PbxForegroundService.start(context)
        } else {
            Log.d(TAG, "PbxForegroundService is alive. Triggering watchdog action...")
            PbxForegroundService.startWithAction(context, PbxForegroundService.ACTION_WATCHDOG)
        }

        // Schedule next periodic check
        schedule(context, RESURRECTION_INTERVAL_MS)
    }

    companion object {
        const val TAG = "ResurrectionReceiver"
        const val ACTION_RESURRECTION = "com.mhrgl.aipbx.ACTION_RESURRECTION"
        const val REQUEST_CODE_RESURRECTION = 9001
        const val RESURRECTION_INTERVAL_MS = 120_000L // 2 minutes

        fun schedule(context: Context, delayMs: Long = RESURRECTION_INTERVAL_MS) {
            val prefs = AppPreferences.getInstance(context)
            if (!prefs.isLoggedIn) return

            val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as? AlarmManager ?: return
            val intent = Intent(context, ResurrectionReceiver::class.java).apply {
                action = ACTION_RESURRECTION
            }
            val flags = PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            val pendingIntent = PendingIntent.getBroadcast(context, REQUEST_CODE_RESURRECTION, intent, flags)

            val triggerAtMillis = SystemClock.elapsedRealtime() + delayMs

            try {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                    if (alarmManager.canScheduleExactAlarms()) {
                        alarmManager.setExactAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, triggerAtMillis, pendingIntent)
                    } else {
                        alarmManager.setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, triggerAtMillis, pendingIntent)
                    }
                } else if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                    alarmManager.setExactAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, triggerAtMillis, pendingIntent)
                } else {
                    alarmManager.set(AlarmManager.ELAPSED_REALTIME_WAKEUP, triggerAtMillis, pendingIntent)
                }
                Log.d(TAG, "Resurrection alarm scheduled in ${delayMs / 1000}s")
            } catch (e: Exception) {
                Log.e(TAG, "Failed to schedule resurrection alarm", e)
            }
        }

        fun cancel(context: Context) {
            val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as? AlarmManager ?: return
            val intent = Intent(context, ResurrectionReceiver::class.java).apply {
                action = ACTION_RESURRECTION
            }
            val flags = PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            val pendingIntent = PendingIntent.getBroadcast(context, REQUEST_CODE_RESURRECTION, intent, flags)
            alarmManager.cancel(pendingIntent)
            Log.d(TAG, "Resurrection alarm cancelled")
        }
    }
}
