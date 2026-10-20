package com.mhrgl.aipbx.data

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.mhrgl.aipbx.model.LoginResponse
import com.mhrgl.aipbx.model.SipCredentials

class AppPreferences(context: Context) {

    private val appContext: Context = context.applicationContext
    private val prefs: SharedPreferences by lazy { createEncryptedPreferences(appContext) }

    @Volatile private var memoryToken: String? = null
    @Volatile private var memorySipPassword: String? = null
    @Volatile private var memoryTurnUser: String? = null
    @Volatile private var memoryTurnPass: String? = null
    @Volatile private var memoryFcmApiKey: String? = null

    var serverUrl: String
        get() = prefs.getString(KEY_SERVER_URL, DEFAULT_SERVER_URL) ?: DEFAULT_SERVER_URL
        set(value) = prefs.edit().putString(KEY_SERVER_URL, value.trim().trimEnd('/')).apply()

    var token: String?
        get() {
            val p = prefs
            return if (isEncrypted) p.getString(KEY_TOKEN, null) else memoryToken
        }
        set(value) {
            val p = prefs
            memoryToken = value
            if (isEncrypted) {
                p.edit().putString(KEY_TOKEN, value).apply()
            }
        }

    var username: String?
        get() = prefs.getString(KEY_USERNAME, null)
        set(value) = prefs.edit().putString(KEY_USERNAME, value).apply()

    var fullName: String?
        get() = prefs.getString(KEY_FULL_NAME, null)
        set(value) = prefs.edit().putString(KEY_FULL_NAME, value).apply()

    var extension: String?
        get() = prefs.getString(KEY_EXTENSION, null)
        set(value) = prefs.edit().putString(KEY_EXTENSION, value).apply()

    var sipUsername: String?
        get() {
            val raw = prefs.getString(KEY_SIP_USERNAME, null)
            val ext = prefs.getString(KEY_EXTENSION, null)
            if (raw != null && raw.endsWith("-mob-webrtc")) {
                return raw
            }
            if (!ext.isNullOrEmpty()) {
                val formatted = "${ext}-mob-webrtc"
                prefs.edit().putString(KEY_SIP_USERNAME, formatted).apply()
                return formatted
            }
            return raw
        }
        set(value) = prefs.edit().putString(KEY_SIP_USERNAME, value).apply()

    var sipPassword: String?
        get() {
            val p = prefs
            return if (isEncrypted) p.getString(KEY_SIP_PASSWORD, null) else memorySipPassword
        }
        set(value) {
            val p = prefs
            memorySipPassword = value
            if (isEncrypted) {
                p.edit().putString(KEY_SIP_PASSWORD, value).apply()
            }
        }

    var wsUrl: String?
        get() = prefs.getString(KEY_WS_URL, null)
        set(value) = prefs.edit().putString(KEY_WS_URL, value).apply()

    var domain: String?
        get() = prefs.getString(KEY_DOMAIN, null)
        set(value) = prefs.edit().putString(KEY_DOMAIN, value).apply()

    var turnUsername: String?
        get() {
            val p = prefs
            return if (isEncrypted) p.getString(KEY_TURN_USER, null) else memoryTurnUser
        }
        set(value) {
            val p = prefs
            memoryTurnUser = value
            if (isEncrypted) {
                p.edit().putString(KEY_TURN_USER, value).apply()
            }
        }

    var turnCredential: String?
        get() {
            val p = prefs
            return if (isEncrypted) p.getString(KEY_TURN_PASS, null) else memoryTurnPass
        }
        set(value) {
            val p = prefs
            memoryTurnPass = value
            if (isEncrypted) {
                p.edit().putString(KEY_TURN_PASS, value).apply()
            }
        }

    var turnUrl: String?
        get() = prefs.getString(KEY_TURN_URL, null)
        set(value) = prefs.edit().putString(KEY_TURN_URL, value).apply()

    /** Video calls: the server's limits, null while video is off for this user (features.php). */
    var videoLimits: com.mhrgl.aipbx.engine.VideoLimits?
        get() {
            val res = prefs.getString(KEY_VIDEO_RES, null) ?: return null
            val p = res.split("x").mapNotNull { it.toIntOrNull() }
            if (p.size != 2) return null
            return com.mhrgl.aipbx.engine.VideoLimits(p[0], p[1], prefs.getInt(KEY_VIDEO_FPS, 24))
        }
        set(value) = prefs.edit().apply {
            if (value == null) remove(KEY_VIDEO_RES) else {
                putString(KEY_VIDEO_RES, "${value.width}x${value.height}")
                putInt(KEY_VIDEO_FPS, value.fps)
            }
        }.apply()

    val isLoggedIn: Boolean
        get() = !token.isNullOrEmpty() && !extension.isNullOrEmpty() && !sipPassword.isNullOrEmpty()

    fun saveLogin(response: LoginResponse) {
        val u = response.user ?: return
        val s = response.sip ?: return
        val rawSipUser = s.sipUsername.ifEmpty { "${u.extension}-mob-webrtc" }
        val finalSipUser = if (rawSipUser.endsWith("-mob-webrtc")) rawSipUser else "${u.extension}-mob-webrtc"
        val p = prefs
        memoryToken = response.token
        if (!s.sipPassword.isNullOrEmpty()) {
            memorySipPassword = s.sipPassword
        }
        s.turn?.let { turn ->
            if (!turn.username.isNullOrEmpty()) memoryTurnUser = turn.username
            if (!turn.credential.isNullOrEmpty()) memoryTurnPass = turn.credential
        }
        response.pushConfig?.let { pc ->
            memoryFcmApiKey = pc.fcmApiKey
        }
        p.edit().apply {
            if (isEncrypted) {
                putString(KEY_TOKEN, response.token)
            }
            putString(KEY_USERNAME, u.username)
            putString(KEY_FULL_NAME, u.fullName)
            putString(KEY_EXTENSION, u.extension)
            putString(KEY_SIP_USERNAME, finalSipUser)
            // T-6: never overwrite the existing non-empty password with an empty one from the server
            if (isEncrypted && !s.sipPassword.isNullOrEmpty()) {
                putString(KEY_SIP_PASSWORD, s.sipPassword)
            }
            if (!s.domain.isNullOrEmpty()) {
                putString(KEY_DOMAIN, s.domain)
            }
            if (!s.wsUrl.isNullOrEmpty()) {
                putString(KEY_WS_URL, s.wsUrl)
            }
            s.turn?.let { turn ->
                if (isEncrypted && !turn.username.isNullOrEmpty()) putString(KEY_TURN_USER, turn.username)
                if (isEncrypted && !turn.credential.isNullOrEmpty()) putString(KEY_TURN_PASS, turn.credential)
                if (!turn.urls.isNullOrEmpty()) putString(KEY_TURN_URL, turn.urls.firstOrNull())
            }
            response.pushConfig?.let { pc ->
                putString(KEY_PUSH_PROVIDER, if (pc.enabled) pc.provider else "none")
                putString(KEY_FCM_PROJECT_ID, pc.fcmProjectId)
                putString(KEY_FCM_APP_ID, pc.fcmAppId)
                if (isEncrypted) {
                    putString(KEY_FCM_API_KEY, pc.fcmApiKey)
                }
                putString(KEY_FCM_SENDER_ID, pc.fcmSenderId)
            }
            apply()
        }
        backupToFallback(response)
    }

    var fcmToken: String?
        get() = prefs.getString(KEY_FCM_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_FCM_TOKEN, value).apply()

    var pushProvider: String
        get() = prefs.getString(KEY_PUSH_PROVIDER, "none") ?: "none"
        set(value) = prefs.edit().putString(KEY_PUSH_PROVIDER, value).apply()

    var fcmProjectId: String?
        get() = prefs.getString(KEY_FCM_PROJECT_ID, null)
        set(value) = prefs.edit().putString(KEY_FCM_PROJECT_ID, value).apply()

    var fcmAppId: String?
        get() = prefs.getString(KEY_FCM_APP_ID, null)
        set(value) = prefs.edit().putString(KEY_FCM_APP_ID, value).apply()

    var fcmApiKey: String?
        get() {
            val p = prefs
            return if (isEncrypted) p.getString(KEY_FCM_API_KEY, null) else memoryFcmApiKey
        }
        set(value) {
            val p = prefs
            memoryFcmApiKey = value
            if (isEncrypted) {
                p.edit().putString(KEY_FCM_API_KEY, value).apply()
            }
        }

    var fcmSenderId: String?
        get() = prefs.getString(KEY_FCM_SENDER_ID, null)
        set(value) = prefs.edit().putString(KEY_FCM_SENDER_ID, value).apply()

    val pushConfig: com.mhrgl.aipbx.model.PushConfig?
        get() {
            val provider = pushProvider
            if (provider == "none") return null
            return com.mhrgl.aipbx.model.PushConfig(
                enabled = true,
                provider = provider,
                fcmProjectId = fcmProjectId,
                fcmAppId = fcmAppId,
                fcmApiKey = fcmApiKey,
                fcmSenderId = fcmSenderId
            )
        }

    var brandTitle: String
        get() = prefs.getString(KEY_BRAND_TITLE, DEFAULT_BRAND_TITLE) ?: DEFAULT_BRAND_TITLE
        set(value) = prefs.edit().putString(KEY_BRAND_TITLE, value).apply()

    var brandSubtitle: String
        get() = prefs.getString(KEY_BRAND_SUB, DEFAULT_BRAND_SUB) ?: DEFAULT_BRAND_SUB
        set(value) = prefs.edit().putString(KEY_BRAND_SUB, value).apply()

    /** App language code (see L10n); English unless the user picks another. Kept on logout. */
    var appLanguage: String
        get() = prefs.getString(KEY_APP_LANGUAGE, null)
            ?: appContext.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE).getString(KEY_APP_LANGUAGE, "en")
            ?: "en"
        set(value) {
            prefs.edit().putString(KEY_APP_LANGUAGE, value).apply()
            appContext.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE).edit().putString(KEY_APP_LANGUAGE, value).apply()
        }

    /** When the "app was put to sleep" warning was last shown (at most once a week). */
    var sleepingWarningShownAt: Long
        get() = prefs.getLong(KEY_SLEEPING_WARNING_SHOWN_AT, 0L)
        set(value) = prefs.edit().putLong(KEY_SLEEPING_WARNING_SHOWN_AT, value).apply()

    /** The automatic battery-optimisation request was shown once (see BatteryPrompt). */
    var batteryPromptShown: Boolean
        get() = prefs.getBoolean(KEY_BATTERY_PROMPT_SHOWN, false) ||
            appContext.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE).getBoolean(KEY_BATTERY_PROMPT_SHOWN, false)
        set(value) {
            prefs.edit().putBoolean(KEY_BATTERY_PROMPT_SHOWN, value).apply()
            appContext.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE).edit().putBoolean(KEY_BATTERY_PROMPT_SHOWN, value).apply()
        }

    /** The full-screen incoming call explanation was shown once (see FullScreenCallPrompt). */
    var fullScreenPromptShown: Boolean
        get() = prefs.getBoolean(KEY_FULL_SCREEN_PROMPT_SHOWN, false) ||
            appContext.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE).getBoolean(KEY_FULL_SCREEN_PROMPT_SHOWN, false)
        set(value) {
            prefs.edit().putBoolean(KEY_FULL_SCREEN_PROMPT_SHOWN, value).apply()
            appContext.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE).edit().putBoolean(KEY_FULL_SCREEN_PROMPT_SHOWN, value).apply()
        }

    /** Persistent flag recording that the user has allowed/configured full-screen incoming calls. */
    var fullScreenUserAllowed: Boolean
        get() = prefs.getBoolean(KEY_FULL_SCREEN_USER_ALLOWED, false) ||
            appContext.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE).getBoolean(KEY_FULL_SCREEN_USER_ALLOWED, false)
        set(value) {
            prefs.edit().putBoolean(KEY_FULL_SCREEN_USER_ALLOWED, value).apply()
            appContext.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE).edit().putBoolean(KEY_FULL_SCREEN_USER_ALLOWED, value).apply()
        }

    var hasSleepingWarning: Boolean
        get() = prefs.getBoolean(KEY_SLEEPING_WARNING, false)
        set(value) = prefs.edit().putBoolean(KEY_SLEEPING_WARNING, value).apply()

    var sendPlusPrefix: Boolean
        get() = prefs.getBoolean(KEY_SEND_PLUS_PREFIX, true)
        set(value) = prefs.edit().putBoolean(KEY_SEND_PLUS_PREFIX, value).apply()

    var lastResurrectionTimestamp: Long
        get() = prefs.getLong(KEY_LAST_RESURRECTION_TS, 0L)
        set(value) = prefs.edit().putLong(KEY_LAST_RESURRECTION_TS, value).apply()

    var deviceUuid: String
        get() {
            var id = prefs.getString(KEY_DEVICE_UUID, null)
            if (id.isNullOrEmpty()) {
                id = java.util.UUID.randomUUID().toString()
                prefs.edit().putString(KEY_DEVICE_UUID, id).apply()
            }
            return id
        }
        set(value) = prefs.edit().putString(KEY_DEVICE_UUID, value).apply()

    var lastTokenRefreshTime: Long
        get() = prefs.getLong(KEY_LAST_TOKEN_REFRESH_TIME, 0L)
        set(value) = prefs.edit().putLong(KEY_LAST_TOKEN_REFRESH_TIME, value).apply()

    fun clearAuth() {
        memoryToken = null
        memorySipPassword = null
        memoryTurnUser = null
        memoryTurnPass = null
        memoryFcmApiKey = null
        prefs.edit().apply {
            remove(KEY_TOKEN)
            remove(KEY_USERNAME)
            remove(KEY_FULL_NAME)
            remove(KEY_EXTENSION)
            remove(KEY_SIP_USERNAME)
            remove(KEY_SIP_PASSWORD)
            remove(KEY_WS_URL)
            remove(KEY_DOMAIN)
            remove(KEY_TURN_USER)
            remove(KEY_TURN_PASS)
            remove(KEY_TURN_URL)
            remove(KEY_VIDEO_RES)
            remove(KEY_FCM_TOKEN)
            remove(KEY_PUSH_PROVIDER)
            remove(KEY_FCM_PROJECT_ID)
            remove(KEY_FCM_APP_ID)
            remove(KEY_FCM_API_KEY)
            remove(KEY_FCM_SENDER_ID)
            apply()
        }
        try {
            appContext.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE).edit().apply {
                remove(KEY_TOKEN)
                remove(KEY_USERNAME)
                remove(KEY_FULL_NAME)
                remove(KEY_EXTENSION)
                remove(KEY_SIP_USERNAME)
                remove(KEY_SIP_PASSWORD)
                remove(KEY_WS_URL)
                remove(KEY_DOMAIN)
                remove(KEY_TURN_USER)
                remove(KEY_TURN_PASS)
                remove(KEY_TURN_URL)
                remove(KEY_VIDEO_RES)
                remove(KEY_FCM_TOKEN)
                remove(KEY_PUSH_PROVIDER)
                remove(KEY_FCM_PROJECT_ID)
                remove(KEY_FCM_APP_ID)
                remove(KEY_FCM_API_KEY)
                remove(KEY_FCM_SENDER_ID)
                apply()
            }
            appContext.getSharedPreferences(OLD_PREFS_NAME, Context.MODE_PRIVATE).edit().clear().apply()
        } catch (e: Exception) {
            Log.w("AppPreferences", "Failed to clear fallback preferences on logout", e)
        }
    }

    private fun backupToFallback(response: LoginResponse) {
        try {
            val u = response.user ?: return
            val s = response.sip
            val rawSipUser = s?.sipUsername?.ifEmpty { "${u.extension}-mob-webrtc" } ?: "${u.extension}-mob-webrtc"
            val finalSipUser = if (rawSipUser.endsWith("-mob-webrtc")) rawSipUser else "${u.extension}-mob-webrtc"

            appContext.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE).edit().apply {
                putString(KEY_SERVER_URL, serverUrl)
                putString(KEY_USERNAME, u.username)
                putString(KEY_FULL_NAME, u.fullName)
                putString(KEY_EXTENSION, u.extension)
                putString(KEY_SIP_USERNAME, finalSipUser)
                if (s != null) {
                    putString(KEY_DOMAIN, s.domain)
                    putString(KEY_WS_URL, s.wsUrl)
                }
                // T-5 / M17: for security, passwords, TURN secrets and the session token are never
                // written to plain-text storage. The token was, until 1.0.71: with it the SIP and TURN
                // passwords could be fetched again (/api/mobile/refresh.php). After a lost keystore
                // the user signs in once more instead.
                remove(KEY_TOKEN)
                remove(KEY_SIP_PASSWORD)
                remove(KEY_TURN_PASS)
                remove(KEY_TURN_USER)
                remove(KEY_FCM_API_KEY)
                apply()
            }
        } catch (e: Exception) {
            Log.w("AppPreferences", "Failed to mirror login to fallback", e)
        }
    }

    companion object {
        const val DEFAULT_SERVER_URL = ""
        const val DEFAULT_BRAND_TITLE = "AiPBX"
        // Empty: the screens show R.string.default_brand_sub (localized).
        const val DEFAULT_BRAND_SUB = ""

        @Volatile
        private var isEncrypted: Boolean = true

        private const val KEY_SERVER_URL = "server_url"
        private const val KEY_BRAND_TITLE = "brand_title"
        private const val KEY_BRAND_SUB = "brand_sub"
        private const val KEY_TOKEN = "auth_token"
        private const val KEY_USERNAME = "username"
        private const val KEY_FULL_NAME = "full_name"
        private const val KEY_EXTENSION = "extension"
        private const val KEY_SIP_USERNAME = "sip_username"
        private const val KEY_SIP_PASSWORD = "sip_password"
        private const val KEY_WS_URL = "ws_url"
        private const val KEY_DOMAIN = "domain"
        private const val KEY_TURN_USER = "turn_user"
        private const val KEY_TURN_PASS = "turn_pass"
        private const val KEY_TURN_URL = "turn_url"
        private const val KEY_FCM_TOKEN = "fcm_token"
        private const val KEY_PUSH_PROVIDER = "push_provider"
        private const val KEY_FCM_PROJECT_ID = "fcm_project_id"
        private const val KEY_FCM_APP_ID = "fcm_app_id"
        private const val KEY_FCM_API_KEY = "fcm_api_key"
        private const val KEY_FCM_SENDER_ID = "fcm_sender_id"
        private const val KEY_SLEEPING_WARNING = "has_sleeping_warning"
        private const val KEY_APP_LANGUAGE = "app_language"
        private const val KEY_BATTERY_PROMPT_SHOWN = "battery_prompt_shown"
        private const val KEY_SLEEPING_WARNING_SHOWN_AT = "sleeping_warning_shown_at"
        private const val KEY_FULL_SCREEN_PROMPT_SHOWN = "full_screen_prompt_shown"
        private const val KEY_FULL_SCREEN_USER_ALLOWED = "full_screen_user_allowed"
        private const val KEY_LAST_RESURRECTION_TS = "last_resurrection_ts"
        private const val KEY_DEVICE_UUID = "device_uuid"
        private const val KEY_LAST_TOKEN_REFRESH_TIME = "last_token_refresh_time"
        private const val KEY_VIDEO_RES = "video_resolution"
        private const val KEY_VIDEO_FPS = "video_fps"
        private const val KEY_SEND_PLUS_PREFIX = "send_plus_prefix"

        private const val OLD_PREFS_NAME = "ai_pbx_prefs"
        private const val SECURE_PREFS_NAME = "ai_pbx_secure_prefs"
        private const val FALLBACK_PREFS_NAME = "ai_pbx_fallback_prefs"

        @Volatile
        private var instance: AppPreferences? = null

        fun getInstance(context: Context): AppPreferences {
            return instance ?: synchronized(this) {
                instance ?: AppPreferences(context.applicationContext).also { instance = it }
            }
        }

        private fun recordKeystoreError(context: Context, e: Throwable) {
            try {
                val file = java.io.File(context.filesDir, "last_keystore_error.txt")
                val timestamp = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.ROOT).format(java.util.Date())
                val content = "[$timestamp] ${e.javaClass.name}: ${e.message}\n${Log.getStackTraceString(e)}\n"
                file.writeText(content)
            } catch (ignored: Exception) {}
        }

        private fun attemptKeystoreRecovery(context: Context, originalError: Exception): SharedPreferences {
            recordKeystoreError(context, originalError)
            return try {
                Log.w("AppPreferences", "Attempting automatic Keystore reset & recovery...", originalError)

                // 1. Delete Keystore master key entry
                try {
                    val keyStore = java.security.KeyStore.getInstance("AndroidKeyStore")
                    keyStore.load(null)
                    keyStore.deleteEntry(MasterKey.DEFAULT_MASTER_KEY_ALIAS)
                    keyStore.deleteEntry("_androidx_security_master_key_")
                } catch (ke: Exception) {
                    Log.w("AppPreferences", "Could not delete Keystore entry", ke)
                }

                // 2. Clear corrupted keyset preference files
                try {
                    context.getSharedPreferences("__androidx_security_crypto_encrypted_prefs_key_keyset__", Context.MODE_PRIVATE).edit().clear().commit()
                    context.getSharedPreferences("__androidx_security_crypto_encrypted_prefs_value_keyset__", Context.MODE_PRIVATE).edit().clear().commit()
                } catch (pe: Exception) {
                    Log.w("AppPreferences", "Could not clear keyset files", pe)
                }

                // 3. Delete existing corrupted encrypted prefs XML
                try {
                    val prefsFile = java.io.File(context.filesDir.parentFile, "shared_prefs/$SECURE_PREFS_NAME.xml")
                    if (prefsFile.exists()) {
                        prefsFile.delete()
                    }
                } catch (fe: Exception) {
                    Log.w("AppPreferences", "Could not delete secure prefs XML", fe)
                }

                // 4. Re-create new MasterKey and secure preferences
                val masterKey = MasterKey.Builder(context)
                    .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                    .build()

                val newSecurePrefs = EncryptedSharedPreferences.create(
                    context,
                    SECURE_PREFS_NAME,
                    masterKey,
                    EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                    EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
                )

                // 5. Restore data from fallback or legacy prefs
                val fallbackPrefs = context.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE)
                val fallbackData = fallbackPrefs.all.ifEmpty {
                    context.getSharedPreferences(OLD_PREFS_NAME, Context.MODE_PRIVATE).all
                }

                if (fallbackData.isNotEmpty()) {
                    val editor = newSecurePrefs.edit()
                    val sensitiveKeys = setOf(KEY_TOKEN, KEY_SIP_PASSWORD, KEY_TURN_PASS, KEY_TURN_USER, KEY_FCM_API_KEY)
                    for ((k, v) in fallbackData) {
                        if (sensitiveKeys.contains(k)) continue
                        when (v) {
                            is String -> editor.putString(k, v)
                            is Boolean -> editor.putBoolean(k, v)
                            is Int -> editor.putInt(k, v)
                            is Long -> editor.putLong(k, v)
                            is Float -> editor.putFloat(k, v)
                        }
                    }
                    editor.commit()
                    Log.i("AppPreferences", "Successfully restored ${fallbackData.size} items to new EncryptedSharedPreferences after Keystore recovery.")
                }

                dropTokenFromFallback(context)
                isEncrypted = true
                newSecurePrefs
            } catch (recoveryEx: Exception) {
                Log.e("AppPreferences", "Keystore recovery failed, falling back to standard fallback storage", recoveryEx)
                recordKeystoreError(context, recoveryEx)
                isEncrypted = false
                dropTokenFromFallback(context)
                context.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE)
            }
        }

        /**
         * Removes any secrets that older versions may have left in the plain-text fallback file.
         */
        private fun dropTokenFromFallback(context: Context) {
            try {
                val fallback = context.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE)
                fallback.edit()
                    .remove(KEY_TOKEN)
                    .remove(KEY_SIP_PASSWORD)
                    .remove(KEY_TURN_PASS)
                    .remove(KEY_TURN_USER)
                    .remove(KEY_FCM_API_KEY)
                    .apply()
            } catch (e: Exception) {
                Log.w("AppPreferences", "Could not clean the fallback preferences", e)
            }
        }

        private fun createEncryptedPreferences(context: Context): SharedPreferences {
            return try {
                val masterKey = MasterKey.Builder(context)
                    .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                    .build()

                val securePrefs = EncryptedSharedPreferences.create(
                    context,
                    SECURE_PREFS_NAME,
                    masterKey,
                    EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                    EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
                )

                // One-time migration: move old plain-text SharedPreferences, if any, to the new secure store
                val oldPrefs = context.getSharedPreferences(OLD_PREFS_NAME, Context.MODE_PRIVATE)
                val allOld = oldPrefs.all
                if (allOld.isNotEmpty()) {
                    val editor = securePrefs.edit()
                    val fallbackEditor = context.getSharedPreferences(FALLBACK_PREFS_NAME, Context.MODE_PRIVATE).edit()
                    val sensitiveKeys = setOf(KEY_TOKEN, KEY_SIP_PASSWORD, KEY_TURN_PASS, KEY_TURN_USER, KEY_FCM_API_KEY)
                    for ((k, v) in allOld) {
                        when (v) {
                            is String -> {
                                editor.putString(k, v)
                                if (!sensitiveKeys.contains(k)) fallbackEditor.putString(k, v)
                            }
                            is Boolean -> {
                                editor.putBoolean(k, v)
                                if (!sensitiveKeys.contains(k)) fallbackEditor.putBoolean(k, v)
                            }
                            is Int -> {
                                editor.putInt(k, v)
                                if (!sensitiveKeys.contains(k)) fallbackEditor.putInt(k, v)
                            }
                            is Long -> {
                                editor.putLong(k, v)
                                if (!sensitiveKeys.contains(k)) fallbackEditor.putLong(k, v)
                            }
                            is Float -> {
                                editor.putFloat(k, v)
                                if (!sensitiveKeys.contains(k)) fallbackEditor.putFloat(k, v)
                            }
                        }
                    }
                    editor.apply()
                    fallbackEditor.apply()
                    oldPrefs.edit().clear().apply()
                    Log.i("AppPreferences", "Legacy preferences migrated to EncryptedSharedPreferences and mirrored to fallback.")
                }
                dropTokenFromFallback(context)
                isEncrypted = true
                securePrefs
            } catch (e: Exception) {
                Log.e("AppPreferences", "Failed to initialize EncryptedSharedPreferences on first attempt", e)
                attemptKeystoreRecovery(context, e)
            }
        }
    }
}
