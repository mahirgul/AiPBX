package com.mhrgl.aipbx.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import com.mhrgl.aipbx.data.AppPreferences

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val action = intent.action
        Log.d("BootReceiver", "Received broadcast: $action")
        if (action == Intent.ACTION_BOOT_COMPLETED || action == Intent.ACTION_MY_PACKAGE_REPLACED) {
            val pendingResult = goAsync()
            Thread {
                try {
                    val prefs = AppPreferences.getInstance(context)
                    if (prefs.isLoggedIn) {
                        Log.d("BootReceiver", "User is logged in, starting PbxForegroundService...")
                        PbxForegroundService.start(context)
                    }
                } catch (e: Exception) {
                    Log.e("BootReceiver", "Error in boot receiver background task", e)
                } finally {
                    pendingResult.finish()
                }
            }.start()
        }
    }
}
