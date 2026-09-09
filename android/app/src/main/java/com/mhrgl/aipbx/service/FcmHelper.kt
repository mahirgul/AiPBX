package com.mhrgl.aipbx.service

import android.content.Context
import android.os.Build
import android.util.Log
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.messaging.FirebaseMessaging
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.model.PushConfig
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

object FcmHelper {
    private const val TAG = "FcmHelper"

    /**
     * Dynamically initialize Firebase at runtime ONLY if FCM is configured and enabled.
     * If push is disabled or provider is 'none', this function returns immediately
     * without making any connection or initializing Google SDKs.
     */
    fun initIfConfigured(context: Context, pushConfig: PushConfig? = null) {
        val prefs = AppPreferences.getInstance(context)
        val config = pushConfig ?: prefs.pushConfig

        if (config == null || !config.enabled || config.provider != "fcm") {
            Log.d(TAG, "Push provider is not FCM (provider='${config?.provider}'). Zero Google connection initialized.")
            return
        }

        val appId = config.fcmAppId?.trim() ?: ""
        val apiKey = config.fcmApiKey?.trim() ?: ""
        val projectId = config.fcmProjectId?.trim() ?: ""
        val senderId = config.fcmSenderId?.trim() ?: ""

        if (appId.isEmpty() || apiKey.isEmpty() || projectId.isEmpty()) {
            Log.w(TAG, "Incomplete FCM credentials provided by server. Skipping Firebase initialization.")
            return
        }

        try {
            val options = FirebaseOptions.Builder()
                .setApplicationId(appId)
                .setApiKey(apiKey)
                .setProjectId(projectId)
                .apply {
                    if (senderId.isNotEmpty()) {
                        setGcmSenderId(senderId)
                    }
                }
                .build()

            // Safe initialization: Check if FirebaseApp is already initialized
            try {
                FirebaseApp.getInstance()
            } catch (e: IllegalStateException) {
                FirebaseApp.initializeApp(context.applicationContext, options)
            }

            Log.i(TAG, "FirebaseApp dynamically initialized for project: $projectId")

            // Fetch registration token
            FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
                if (task.isSuccessful && !task.result.isNullOrEmpty()) {
                    val token = task.result
                    Log.i(TAG, "FCM registration token acquired: ${token.take(15)}...")
                    prefs.fcmToken = token
                    sendTokenToServer(context, token)
                } else {
                    Log.w(TAG, "Failed to retrieve FCM registration token", task.exception)
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error initializing dynamic FirebaseApp", e)
        }
    }

    fun sendTokenToServer(context: Context, fcmToken: String) {
        val prefs = AppPreferences.getInstance(context)
        if (!prefs.isLoggedIn) return

        val sUrl = prefs.serverUrl
        if (sUrl.isEmpty()) return
        val authToken = prefs.token ?: return

        CoroutineScope(Dispatchers.IO).launch {
            try {
                val apiClient = ApiClient { prefs }
                val deviceName = "${Build.MANUFACTURER} ${Build.MODEL}"
                val res = apiClient.registerFcmToken(
                    baseUrl = sUrl,
                    token = authToken,
                    fcmToken = fcmToken,
                    deviceId = prefs.deviceUuid,
                    deviceName = deviceName
                )
                res.onSuccess {
                    Log.i(TAG, "FCM token successfully registered with AI PBX server.")
                }.onFailure { err ->
                    Log.w(TAG, "Failed to register FCM token with server: ${err.message}")
                }
            } catch (e: Exception) {
                Log.e(TAG, "Exception registering FCM token with server", e)
            }
        }
    }
}
