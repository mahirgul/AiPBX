package com.mhrgl.aipbx

import android.app.Application
import android.util.Log
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.service.PbxForegroundService
import java.io.File
import java.io.PrintWriter
import java.io.StringWriter
import java.util.Date

class AiPbxApp : Application() {
    override fun onCreate() {
        super.onCreate()

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
