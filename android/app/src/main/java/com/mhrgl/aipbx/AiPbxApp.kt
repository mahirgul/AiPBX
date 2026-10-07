package com.mhrgl.aipbx

import android.app.Activity
import android.app.Application
import android.os.Bundle
import com.mhrgl.aipbx.data.ChatWebSocketManager
import android.util.Log
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.service.PbxForegroundService
import com.mhrgl.aipbx.util.L10n
import java.io.File
import java.io.PrintWriter
import java.io.StringWriter
import java.util.Date

class AiPbxApp : Application() {
    /** Started (visible) activities; the user counts as online in chat while > 0. */
    private var startedActivities = 0

    override fun onCreate() {
        super.onCreate()
        // App language before any screen is created: English by default, Turkish when picked.
        L10n.init(this)

        registerActivityLifecycleCallbacks(object : ActivityLifecycleCallbacks {
            override fun onActivityStarted(activity: Activity) {
                if (startedActivities++ == 0) ChatWebSocketManager.instance.setActive(true)
            }
            override fun onActivityStopped(activity: Activity) {
                if (startedActivities > 0 && --startedActivities == 0) ChatWebSocketManager.instance.setActive(false)
            }
            override fun onActivityCreated(activity: Activity, savedInstanceState: Bundle?) {}
            override fun onActivityResumed(activity: Activity) {}
            override fun onActivityPaused(activity: Activity) {}
            override fun onActivitySaveInstanceState(activity: Activity, outState: Bundle) {}
            override fun onActivityDestroyed(activity: Activity) {}
        })

        // Global Uncaught Exception Crash Logger
        val defaultHandler = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            try {
                val sw = StringWriter()
                throwable.printStackTrace(PrintWriter(sw))
                val stackTrace = sw.toString()
                Log.e("AiPbxCrash", "FATAL CRASH: $stackTrace")

                val crashFile = File(filesDir, "last_crash.txt")
                crashFile.writeText("Zaman: ${Date()}\nThread: ${thread.name}\nHata: ${throwable.javaClass.name}\nMesaj: ${throwable.message}\n\nStack:\n$stackTrace")
            } catch (e: Exception) {
                Log.e("AiPbxCrash", "Crash log could not be written", e)
            }
            defaultHandler?.uncaughtException(thread, throwable)
        }

        // Only attempt to start service if logged in, safely wrapped
        try {
            val prefs = AppPreferences.getInstance(this)
            if (prefs.isLoggedIn) {
                PbxForegroundService.start(this)
            }
        } catch (e: Exception) {
            Log.e("AiPbxApp", "Service start error in onCreate", e)
        }
    }
}
