package com.mhrgl.aipbx.service

import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.MediaPlayer
import android.media.Ringtone
import android.media.RingtoneManager
import android.net.ConnectivityManager
import android.net.Network
import android.net.wifi.WifiManager
import android.os.Binder
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.provider.Settings
import android.util.Log
import androidx.core.app.NotificationCompat
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.data.ChatEventListener
import com.mhrgl.aipbx.data.ChatWebSocketManager
import com.mhrgl.aipbx.engine.SipEngineListener
import com.mhrgl.aipbx.engine.SipWebRtcEngine
import com.mhrgl.aipbx.model.CallStatus
import com.mhrgl.aipbx.model.ChatMessage
import com.mhrgl.aipbx.model.ConnectionStatus
import com.mhrgl.aipbx.ui.CallActivity
import com.mhrgl.aipbx.ui.ChatActivity
import com.mhrgl.aipbx.ui.DialerActivity
import com.mhrgl.aipbx.ui.IncomingCallActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import java.util.concurrent.CopyOnWriteArrayList

class PbxForegroundService : Service(), SipEngineListener, ChatEventListener {

    private val binder = LocalBinder()
    lateinit var engine: SipWebRtcEngine
        private set
    lateinit var prefs: AppPreferences
        private set

    private var wakeLock: PowerManager.WakeLock? = null
    private var wifiLock: WifiManager.WifiLock? = null

    private var ringtone: Ringtone? = null
    private var mediaPlayer: MediaPlayer? = null
    private var vibrator: Vibrator? = null

    private var connectivityManager: ConnectivityManager? = null
    private var networkCallback: ConnectivityManager.NetworkCallback? = null

    private val listeners = CopyOnWriteArrayList<SipEngineListener>()
    private val apiClient by lazy { ApiClient { prefs } }
    private val serviceScope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    // Reconnection exponential backoff & network awareness (M28)
    private var isNetworkAvailable = true
    private var currentReconnectDelayMs = INITIAL_RECONNECT_DELAY_MS

    // Native Doze/Sleep Watchdog: Her 2 dakikada bir SIP kaydini tazeler (M1, M2, M14)
    private val watchdogHandler = Handler(Looper.getMainLooper())
    private val watchdogRunnable = object : Runnable {
        override fun run() {
            runWatchdogCheck()
            watchdogHandler.postDelayed(this, WATCHDOG_INTERVAL_MS)
        }
    }

    private fun runWatchdogCheck() {
        try {
            if (prefs.isLoggedIn) {
                checkAndRefreshToken()
                if (!engine.isEngineReady) {
                    Log.w(TAG, "Watchdog: Engine is not ready, re-triggering connectSip()")
                    connectSip()
                } else if (engine.currentConnectionStatus != ConnectionStatus.CONNECTED) {
                    Log.w(TAG, "Watchdog: Engine is DISCONNECTED, re-triggering connectSip() (M14)")
                    connectSip()
                } else {
                    Log.d(TAG, "Watchdog: Periodic SIP keepalive check (reRegister & checkEngineStatus)")
                    engine.reRegister()
                    engine.checkEngineStatus()
                }
                connectChatWs()
            }
        } catch (e: Exception) {
            Log.e(TAG, "Watchdog error", e)
        }
    }

    private fun connectChatWs() {
        if (!prefs.isLoggedIn) return
        val sUrl = prefs.serverUrl
        val tok = prefs.token
        if (!sUrl.isNullOrEmpty() && !tok.isNullOrEmpty()) {
            ChatWebSocketManager.instance.connect(sUrl, tok)
        }
    }

    private val reconnectHandler = Handler(Looper.getMainLooper())
    private val reconnectRunnable = Runnable {
        if (prefs.isLoggedIn && engine.currentConnectionStatus != ConnectionStatus.CONNECTED && isNetworkAvailable) {
            Log.i(TAG, "Auto-reconnecting SIP (backoff delay was ${currentReconnectDelayMs}ms) (M14/M28)...")
            connectSip()
        }
    }

    private fun checkAndRefreshToken() {
        val now = System.currentTimeMillis()
        // Her 24 saatte bir token ve TURN oturumunu tazele (M5)
        if (now - prefs.lastTokenRefreshTime > 24 * 3600 * 1000L && prefs.isLoggedIn) {
            val sUrl = prefs.serverUrl
            val tok = prefs.token
            if (!sUrl.isNullOrEmpty() && !tok.isNullOrEmpty()) {
                serviceScope.launch {
                    val res = apiClient.refreshToken(sUrl, tok)
                    res.onSuccess { newLogin ->
                        Log.i(TAG, "Session token & TURN credentials refreshed successfully (M5)")
                        prefs.saveLogin(newLogin)
                        FcmHelper.initIfConfigured(this@PbxForegroundService, newLogin.pushConfig)
                        prefs.lastTokenRefreshTime = System.currentTimeMillis()
                    }.onFailure { err ->
                        Log.w(TAG, "Session token refresh failed: ${err.message} (M5)")
                    }
                }
            }
        }
    }

    inner class LocalBinder : Binder() {
        fun getService(): PbxForegroundService = this@PbxForegroundService
    }

    override fun onBind(intent: Intent?): IBinder = binder

    @SuppressLint("WakelockTimeout")
    override fun onCreate() {
        super.onCreate()
        isServiceRunning = true
        prefs = AppPreferences.getInstance(this)
        engine = SipWebRtcEngine(this)
        engine.listener = this

        createNotificationChannels()

        // Initialize FCM if configured on server (runtime dynamic)
        FcmHelper.initIfConfigured(this)

        // Acquire WakeLock to survive Screen-Off
        val powerManager = getSystemService(Context.POWER_SERVICE) as PowerManager
        wakeLock = powerManager.newWakeLock(
            PowerManager.PARTIAL_WAKE_LOCK,
            "AiPbxPhone:ServiceWakeLock"
        ).apply {
            setReferenceCounted(false)
            acquire()
        }

        // Acquire WifiLock
        val wifiManager = applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
        wifiLock = wifiManager.createWifiLock(
            WifiManager.WIFI_MODE_FULL_HIGH_PERF,
            "AiPbxPhone:ServiceWifiLock"
        ).apply {
            setReferenceCounted(false)
            acquire()
        }

        // Vibrator
        vibrator = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val vm = getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager
            vm.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            getSystemService(Context.VIBRATOR_SERVICE) as Vibrator
        }

        // Safe startForeground compatible with Android 14+ (specialUse eliminates Android 15 6-hour limit)
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
                val fgsType = android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE
                startForeground(NOTIFICATION_ID_SERVICE, buildServiceNotification(ConnectionStatus.CONNECTING), fgsType)
            } else {
                startForeground(NOTIFICATION_ID_SERVICE, buildServiceNotification(ConnectionStatus.CONNECTING))
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error in startForeground", e)
        }

        // Android 14+ (API 34+) USE_FULL_SCREEN_INTENT denetimi (M10)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            if (!nm.canUseFullScreenIntent()) {
                Log.w(TAG, "NotificationManager.canUseFullScreenIntent() is FALSE! Incoming calls might only show heads-up notification.")
            }
        }

        // Auto-reconnect on network change (WiFi <-> LTE) (M7)
        try {
            connectivityManager = getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
            networkCallback = object : ConnectivityManager.NetworkCallback() {
                override fun onAvailable(network: Network) {
                    Log.d(TAG, "Default network available, resetting backoff and triggering immediate SIP reconnect (M28)")
                    isNetworkAvailable = true
                    currentReconnectDelayMs = INITIAL_RECONNECT_DELAY_MS
                    reconnectHandler.removeCallbacks(reconnectRunnable)
                    if (prefs.isLoggedIn) {
                        connectSip()
                        connectChatWs()
                    }
                }

                override fun onLost(network: Network) {
                    Log.d(TAG, "Default network lost, cancelling pending reconnects (M28)")
                    isNetworkAvailable = false
                    reconnectHandler.removeCallbacks(reconnectRunnable)
                }
            }
            networkCallback?.let { connectivityManager?.registerDefaultNetworkCallback(it) }
        } catch (e: Exception) {
            Log.e(TAG, "Could not register network callback", e)
        }

        connectSip()
        ChatWebSocketManager.instance.addListener(this)
        connectChatWs()

        // Periyodik native watchdog'u baslat (2 dakikada bir) (M1, M2)
        watchdogHandler.postDelayed(watchdogRunnable, WATCHDOG_INTERVAL_MS)

        // AlarmManager tabanli Doze-dayanikli diriltme ve watchdog alarmini kur (§4.3)
        ResurrectionReceiver.schedule(this, WATCHDOG_INTERVAL_MS)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_ANSWER -> {
                if (androidx.core.content.ContextCompat.checkSelfPermission(
                        this,
                        android.Manifest.permission.RECORD_AUDIO
                    ) == android.content.pm.PackageManager.PERMISSION_GRANTED
                ) {
                    stopRinging()
                    engine.answerCall()
                    val callIntent = Intent(this, CallActivity::class.java).apply {
                        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
                    }
                    startActivity(callIntent)
                } else {
                    // Mikrofon izni yoksa doğrudan cevaplama yerine izin istemesi için IncomingCallActivity'yi öne getir
                    val incomingIntent = Intent(this, com.mhrgl.aipbx.ui.IncomingCallActivity::class.java).apply {
                        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
                        putExtra(com.mhrgl.aipbx.ui.IncomingCallActivity.EXTRA_CALLER_NAME, engine.activeCallerName)
                        putExtra(com.mhrgl.aipbx.ui.IncomingCallActivity.EXTRA_CALLER_NUMBER, engine.activeCallerNumber)
                    }
                    startActivity(incomingIntent)
                }
            }
            ACTION_DECLINE -> {
                stopRinging()
                cancelIncomingCallNotification()
                engine.rejectCall()
            }
            ACTION_RECONNECT -> {
                connectSip()
            }
            ACTION_WATCHDOG -> {
                Log.d(TAG, "onStartCommand: ACTION_WATCHDOG received")
                runWatchdogCheck()
            }
        }
        return START_STICKY
    }

    fun connectSip() {
        if (!prefs.isLoggedIn) return

        val wsUrl = prefs.wsUrl
        val ext = prefs.extension ?: return
        var sipUser = prefs.sipUsername ?: "${ext}-mob-webrtc"
        if (!sipUser.endsWith("-mob-webrtc")) {
            sipUser = "${ext}-mob-webrtc"
            prefs.sipUsername = sipUser
        }
        val sipPass = prefs.sipPassword
        val domain = prefs.domain
        val displayName = prefs.fullName ?: ext

        // T-5 / Keystore kurtarma sonrası: SIP parolası henüz şifreli depoda yoksa,
        // oturum jetonuyla sunucudan taze SIP kimliklerini çekip kaydet
        if (sipPass.isNullOrEmpty() || wsUrl.isNullOrEmpty() || domain.isNullOrEmpty()) {
            Log.i(TAG, "SIP credentials missing from encrypted storage. Fetching fresh credentials via session token (T-5)...")
            val sUrl = prefs.serverUrl
            val tok = prefs.token
            if (!sUrl.isNullOrEmpty() && !tok.isNullOrEmpty()) {
                serviceScope.launch {
                    val res = apiClient.refreshToken(sUrl, tok)
                    res.onSuccess { newLogin ->
                        Log.i(TAG, "Session restored and fresh SIP credentials saved to encrypted storage")
                        prefs.saveLogin(newLogin)
                        FcmHelper.initIfConfigured(this@PbxForegroundService, newLogin.pushConfig)
                        connectSip()
                    }.onFailure { err ->
                        Log.w(TAG, "Failed to refresh SIP credentials after recovery: ${err.message}")
                    }
                }
            }
            return
        }

        Log.d(TAG, "Connecting SIP: user=$sipUser ws=$wsUrl")
        engine.register(
            wsUrl = wsUrl,
            sipUsername = sipUser,
            sipPassword = sipPass,
            domain = domain,
            displayName = displayName,
            turnUrl = prefs.turnUrl,
            turnUser = prefs.turnUsername,
            turnPass = prefs.turnCredential
        )
    }

    fun registerListener(listener: SipEngineListener) {
        if (!listeners.contains(listener)) listeners.add(listener)
    }

    fun unregisterListener(listener: SipEngineListener) {
        listeners.remove(listener)
    }

    // --- SipEngineListener Callbacks ---

    override fun onConnectionStatusChanged(status: ConnectionStatus) {
        updateServiceNotification(status)
        listeners.forEach { it.onConnectionStatusChanged(status) }

        if (status == ConnectionStatus.DISCONNECTED && prefs.isLoggedIn) {
            if (isNetworkAvailable) {
                Log.d(TAG, "Connection status DISCONNECTED, scheduling auto-reconnect in ${currentReconnectDelayMs}ms (M14/M28)...")
                reconnectHandler.removeCallbacks(reconnectRunnable)
                reconnectHandler.postDelayed(reconnectRunnable, currentReconnectDelayMs)
                // Üstel geri çekilme (3 sn -> 6 -> 12 -> 24 -> 48 -> 60 sn tavan)
                currentReconnectDelayMs = (currentReconnectDelayMs * 2).coerceAtMost(MAX_RECONNECT_DELAY_MS)
            } else {
                Log.d(TAG, "Connection status DISCONNECTED, network is unavailable. Waiting for network available event (M28).")
            }
        } else if (status == ConnectionStatus.CONNECTED) {
            reconnectHandler.removeCallbacks(reconnectRunnable)
            currentReconnectDelayMs = INITIAL_RECONNECT_DELAY_MS
        }
    }

    override fun onCallStatusChanged(status: CallStatus) {
        if (status == CallStatus.ACTIVE || status == CallStatus.ENDED || status == CallStatus.IDLE) {
            stopRinging()
            cancelIncomingCallNotification()
        }
        updateServiceNotification(engine.currentConnectionStatus, status)
        listeners.forEach { it.onCallStatusChanged(status) }
    }

    override fun onIncomingCall(callerName: String, callerNumber: String) {
        val cleanNumber = callerNumber.replace(Regex("-(mob-webrtc|webrtc|sip)$"), "")
        val cleanName = if (callerName == callerNumber) cleanNumber else callerName.replace(Regex("-(mob-webrtc|webrtc|sip)$"), "")

        // Explicitly wake up screen if phone is asleep / screen is off
        try {
            val pm = getSystemService(Context.POWER_SERVICE) as? PowerManager
            @Suppress("DEPRECATION")
            val screenWakeLock = pm?.newWakeLock(
                PowerManager.SCREEN_BRIGHT_WAKE_LOCK or PowerManager.ACQUIRE_CAUSES_WAKEUP or PowerManager.ON_AFTER_RELEASE,
                "aipbx:incoming_call_wakeup"
            )
            screenWakeLock?.acquire(20000) // 20s screen on
        } catch (e: Exception) {
            Log.w(TAG, "Could not acquire screen wake lock", e)
        }

        startRinging()
        showIncomingCallNotification(cleanName, cleanNumber)

        // Launch full-screen incoming call UI safely (fallback to notification if background restricted)
        try {
            val incomingIntent = Intent(this, IncomingCallActivity::class.java).apply {
                putExtra(IncomingCallActivity.EXTRA_CALLER_NAME, cleanName)
                putExtra(IncomingCallActivity.EXTRA_CALLER_NUMBER, cleanNumber)
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            }
            startActivity(incomingIntent)
        } catch (e: Exception) {
            Log.w(TAG, "Direct background startActivity failed, fullScreenIntent notification handles it", e)
        }

        listeners.forEach { it.onIncomingCall(cleanName, cleanNumber) }
    }

    // --- Ringing & Audio ---

    private fun startRinging() {
        try {
            val audioManager = getSystemService(Context.AUDIO_SERVICE) as AudioManager
            val ringerMode = audioManager.ringerMode

            // 1. Sessiz Mod: Telefon sessizdeyken ses de titreşim de olmamalı
            if (ringerMode == AudioManager.RINGER_MODE_SILENT) {
                Log.d(TAG, "Ringer mode is SILENT; skipping ringtone and vibration")
                return
            }

            // 2. Titreşim kontrolü: Titreşim modunda kesinlikle titremeli, Normal modda sistem ayarına göre
            val shouldVibrate = when (ringerMode) {
                AudioManager.RINGER_MODE_VIBRATE -> true
                AudioManager.RINGER_MODE_NORMAL -> {
                    try {
                        Settings.System.getInt(contentResolver, "vibrate_when_ringing", 1) != 0
                    } catch (e: Exception) {
                        true
                    }
                }
                else -> false
            }

            if (shouldVibrate) {
                val pattern = longArrayOf(0, 1000, 1000)
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    vibrator?.vibrate(
                        VibrationEffect.createWaveform(pattern, 0),
                        AudioAttributes.Builder()
                            .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                            .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                            .build()
                    )
                } else {
                    @Suppress("DEPRECATION")
                    vibrator?.vibrate(pattern, 0)
                }
            }

            // 3. Zil Sesi kontrolü: Yalnızca NORMAL modda çalar
            if (ringerMode == AudioManager.RINGER_MODE_NORMAL && ringtone == null && mediaPlayer == null) {
                // Öncelik: Kullanıcının telefon ayarlarında seçtiği kendi sistem zil sesi
                val ringtoneUri = try {
                    RingtoneManager.getActualDefaultRingtoneUri(this, RingtoneManager.TYPE_RINGTONE)
                        ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)
                } catch (e: Exception) {
                    RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)
                }

                var playedWithRingtone = false
                if (ringtoneUri != null) {
                    try {
                        val r = RingtoneManager.getRingtone(this, ringtoneUri)
                        if (r != null) {
                            r.audioAttributes = AudioAttributes.Builder()
                                .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                                .build()
                            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                                r.isLooping = true
                            }
                            r.play()
                            ringtone = r
                            playedWithRingtone = true
                            Log.d(TAG, "Playing default system ringtone via Ringtone API")
                        }
                    } catch (e: Exception) {
                        Log.w(TAG, "Ringtone API failed, falling back to MediaPlayer", e)
                    }
                }

                // Fallback: MediaPlayer ile raw veya default uri
                if (!playedWithRingtone) {
                    mediaPlayer = try {
                        MediaPlayer.create(this, R.raw.ringtone)
                    } catch (e: Exception) {
                        MediaPlayer.create(this, RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE))
                    }
                    mediaPlayer?.apply {
                        setAudioAttributes(
                            AudioAttributes.Builder()
                                .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                                .build()
                        )
                        isLooping = true
                        start()
                    }
                    Log.d(TAG, "Playing ringtone via MediaPlayer fallback")
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error starting ringtone", e)
        }
    }

    fun stopRinging() {
        try {
            ringtone?.stop()
            ringtone = null
            mediaPlayer?.stop()
            mediaPlayer?.release()
            mediaPlayer = null
            vibrator?.cancel()
        } catch (e: Exception) {
            Log.e(TAG, "Error stopping ringtone", e)
        }
    }

    // --- Notifications ---

    private fun createNotificationChannels() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val nm = getSystemService(NotificationManager::class.java)

            // 1. Silent persistent service channel
            val serviceChannel = NotificationChannel(
                CHANNEL_ID_SERVICE,
                getString(R.string.notif_channel_service),
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "Santral arka plan bağlantısını canlı tutar"
                setShowBadge(false)
            }
            nm.createNotificationChannel(serviceChannel)

            // 2. High priority incoming calls channel
            val callChannel = NotificationChannel(
                CHANNEL_ID_CALLS,
                getString(R.string.notif_channel_calls),
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = "Gelen çağrı bildirimleri"
                setSound(null, null) // Audio is handled via MediaPlayer
                enableVibration(false)
                lockscreenVisibility = Notification.VISIBILITY_PUBLIC
            }
            nm.createNotificationChannel(callChannel)

            // 3. High priority chat messages channel
            val chatChannel = NotificationChannel(
                CHANNEL_ID_CHAT,
                getString(R.string.notif_channel_chat),
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = "Gelen sohbet ve anlık mesaj bildirimleri"
                enableVibration(true)
                setShowBadge(true)
                lockscreenVisibility = Notification.VISIBILITY_PRIVATE
            }
            nm.createNotificationChannel(chatChannel)
        }
    }

    private fun buildServiceNotification(
        status: ConnectionStatus = engine.currentConnectionStatus,
        callStatus: CallStatus = engine.currentCallStatus
    ): Notification {
        val ext = prefs.extension ?: "--"
        val activeNumber = engine.activeCallerNumber.ifEmpty { engine.activeCallerName }

        val title: String
        val content: String
        val targetIntent: Intent

        when (callStatus) {
            CallStatus.CONNECTING, CallStatus.RINGING_OUTGOING -> {
                title = getString(R.string.notif_call_calling_title)
                content = getString(R.string.notif_call_calling_desc, activeNumber)
                targetIntent = Intent(this, CallActivity::class.java).apply {
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                }
            }
            CallStatus.ACTIVE -> {
                title = getString(R.string.notif_call_active_title)
                content = getString(R.string.notif_call_active_desc, activeNumber)
                targetIntent = Intent(this, CallActivity::class.java).apply {
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                }
            }
            CallStatus.ON_HOLD -> {
                title = getString(R.string.notif_call_on_hold_title)
                content = getString(R.string.notif_call_on_hold_desc, activeNumber)
                targetIntent = Intent(this, CallActivity::class.java).apply {
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                }
            }
            CallStatus.RINGING_INCOMING -> {
                title = getString(R.string.notif_call_incoming_title)
                content = getString(R.string.notif_call_incoming_desc, activeNumber)
                targetIntent = Intent(this, IncomingCallActivity::class.java).apply {
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                }
            }
            else -> {
                val statusText = when (status) {
                    ConnectionStatus.CONNECTED -> getString(R.string.status_connected)
                    ConnectionStatus.CONNECTING -> getString(R.string.status_connecting)
                    ConnectionStatus.DISCONNECTED -> getString(R.string.status_disconnected)
                }
                title = getString(R.string.notif_service_title)
                content = getString(R.string.notif_service_text, ext, statusText)
                targetIntent = Intent(this, DialerActivity::class.java).apply {
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                }
            }
        }

        val pendingOpen = PendingIntent.getActivity(
            this, 0, targetIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val isCallActive = callStatus == CallStatus.ACTIVE || callStatus == CallStatus.CONNECTING ||
                callStatus == CallStatus.RINGING_OUTGOING || callStatus == CallStatus.ON_HOLD

        val builder = NotificationCompat.Builder(this, CHANNEL_ID_SERVICE)
            .setContentTitle(title)
            .setContentText(content)
            .setSmallIcon(R.drawable.ic_phone)
            .setContentIntent(pendingOpen)
            .setOngoing(true)
            .setCategory(if (isCallActive) NotificationCompat.CATEGORY_CALL else NotificationCompat.CATEGORY_SERVICE)

        if (isCallActive) {
            val declineIntent = Intent(this, PbxForegroundService::class.java).apply {
                action = ACTION_DECLINE
            }
            val pendingDecline = PendingIntent.getService(
                this, 3, declineIntent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            builder.addAction(R.drawable.ic_call_end, getString(R.string.action_hangup), pendingDecline)
        }

        return builder.build()
    }

    private fun updateServiceNotification(
        status: ConnectionStatus = engine.currentConnectionStatus,
        callStatus: CallStatus = engine.currentCallStatus
    ) {
        val isCallActive = callStatus == CallStatus.ACTIVE || callStatus == CallStatus.CONNECTING ||
                callStatus == CallStatus.RINGING_OUTGOING || callStatus == CallStatus.ON_HOLD ||
                callStatus == CallStatus.RINGING_INCOMING

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            try {
                val fgsType = if (isCallActive) {
                    android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE or
                            android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE
                } else {
                    android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE
                }
                startForeground(NOTIFICATION_ID_SERVICE, buildServiceNotification(status, callStatus), fgsType)
                return
            } catch (e: Exception) {
                Log.e(TAG, "Could not update startForeground with new type", e)
            }
        } else if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            try {
                if (isCallActive) {
                    startForeground(
                        NOTIFICATION_ID_SERVICE,
                        buildServiceNotification(status, callStatus),
                        android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE
                    )
                    return
                }
            } catch (e: Exception) {
                Log.e(TAG, "Could not update startForeground with microphone type", e)
            }
        }

        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        nm.notify(NOTIFICATION_ID_SERVICE, buildServiceNotification(status, callStatus))
    }

    private fun showIncomingCallNotification(callerName: String, callerNumber: String) {
        val fullScreenIntent = Intent(this, IncomingCallActivity::class.java).apply {
            putExtra(IncomingCallActivity.EXTRA_CALLER_NAME, callerName)
            putExtra(IncomingCallActivity.EXTRA_CALLER_NUMBER, callerNumber)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        }
        val fullScreenPending = PendingIntent.getActivity(
            this, 1, fullScreenIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        // Answer action
        val answerIntent = Intent(this, PbxForegroundService::class.java).apply {
            action = ACTION_ANSWER
        }
        val pendingAnswer = PendingIntent.getService(
            this, 2, answerIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        // Decline action
        val declineIntent = Intent(this, PbxForegroundService::class.java).apply {
            action = ACTION_DECLINE
        }
        val pendingDecline = PendingIntent.getService(
            this, 3, declineIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(this, CHANNEL_ID_CALLS)
            .setContentTitle(getString(R.string.notif_incoming_call_title, callerName))
            .setContentText(getString(R.string.notif_incoming_call_desc, callerNumber))
            .setSmallIcon(R.drawable.ic_phone)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setFullScreenIntent(fullScreenPending, true)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setAutoCancel(true)
            .setOngoing(true)
            .addAction(R.drawable.ic_call_end, getString(R.string.action_decline), pendingDecline)
            .addAction(R.drawable.ic_phone, getString(R.string.action_answer), pendingAnswer)
            .build()

        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        nm.notify(NOTIFICATION_ID_CALL, notification)
    }

    fun cancelIncomingCallNotification() {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        nm.cancel(NOTIFICATION_ID_CALL)
    }

    // --- Chat Notifications ---

    override fun onNewMessage(message: ChatMessage) {
        val myExt = prefs.extension ?: ""
        // Kendi gönderdiğimiz mesajlar için bildirim basma
        if (message.senderExt.isNotEmpty() && message.senderExt == myExt) {
            return
        }
        // Kullanıcı şu an o sohbet ekranında ise bildirim basma (ekranda canlı görüyor)
        if (ChatActivity.activeConversationId == message.conversationId) {
            return
        }

        showChatMessageNotification(message)
    }

    private fun showChatMessageNotification(message: ChatMessage) {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager ?: return

        val title = if (!message.senderName.isNullOrEmpty() && message.senderName != message.senderExt) {
            "${message.senderName} (#${message.senderExt})"
        } else {
            "Dahili #${message.senderExt}"
        }

        val body = when (message.msgType) {
            "image" -> "📷 Fotoğraf"
            "file" -> "📎 Dosya: ${message.fileName ?: "Belge"}"
            "audio" -> "🎙️ Ses kaydı"
            "video" -> "🎥 Video"
            else -> if (!message.message.isNullOrEmpty()) message.message else "Yeni bir mesaj gönderdi."
        }

        val intent = Intent(this, ChatActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra(ChatActivity.EXTRA_CONV_ID, message.conversationId)
            putExtra(ChatActivity.EXTRA_TARGET_EXT, message.senderExt)
            putExtra(ChatActivity.EXTRA_TARGET_NAME, message.senderName)
        }
        val pendingIntent = PendingIntent.getActivity(
            this,
            message.conversationId,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(this, CHANNEL_ID_CHAT)
            .setContentTitle(title)
            .setContentText(body)
            .setSmallIcon(R.drawable.ic_chat)
            .setContentIntent(pendingIntent)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setDefaults(NotificationCompat.DEFAULT_ALL)
            .setAutoCancel(true)
            .build()

        nm.notify(10000 + (message.conversationId % 1000), notification)
    }

    // Android 15+ (API 35+) Foreground Service Timeout Handler (M19)
    override fun onTimeout(startId: Int, fgsType: Int) {
        Log.w(TAG, "Foreground service timeout reached for fgsType=$fgsType on Android 15+. Stopping service safely to avoid exception (M19).")
        stopSelf(startId)
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        super.onTaskRemoved(rootIntent)
        Log.w(TAG, "onTaskRemoved: App removed from recent tasks. Scheduling quick resurrection alarm...")
        if (prefs.isLoggedIn) {
            // Kullanici uygulamayi son kullanilanlardan kaydirdiysa, 3 sn icinde servisi dirilt
            ResurrectionReceiver.schedule(this, 3000L)
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        isServiceRunning = false
        if (prefs.isLoggedIn) {
            Log.w(TAG, "PbxForegroundService destroyed while logged in. Scheduling resurrection in 5s...")
            ResurrectionReceiver.schedule(this, 5000L)
        } else {
            ResurrectionReceiver.cancel(this)
        }
        ChatWebSocketManager.instance.removeListener(this)
        serviceScope.cancel()
        watchdogHandler.removeCallbacks(watchdogRunnable)
        reconnectHandler.removeCallbacks(reconnectRunnable)
        try {
            networkCallback?.let { connectivityManager?.unregisterNetworkCallback(it) }
        } catch (e: Exception) {
            // Ignored
        }
        stopRinging()
        cancelIncomingCallNotification()
        engine.destroy()

        wakeLock?.let { if (it.isHeld) it.release() }
        wifiLock?.let { if (it.isHeld) it.release() }
        Log.d(TAG, "PbxForegroundService destroyed")
    }

    companion object {
        const val TAG = "PbxService"

        @Volatile
        var isServiceRunning: Boolean = false
            private set

        const val WATCHDOG_INTERVAL_MS = 120_000L // 2 dakika (M1, M2 Keepalive & Recovery)
        const val INITIAL_RECONNECT_DELAY_MS = 3000L // Üstel geri çekilme başlangıç gecikmesi (M28)
        const val MAX_RECONNECT_DELAY_MS = 60_000L // Üstel geri çekilme tavanı (1 dakika) (M28)

        const val CHANNEL_ID_SERVICE = "ai_pbx_service_channel"
        const val CHANNEL_ID_CALLS = "ai_pbx_calls_channel"
        const val CHANNEL_ID_CHAT = "ai_pbx_chat_channel"

        const val NOTIFICATION_ID_SERVICE = 1001
        const val NOTIFICATION_ID_CALL = 1002

        const val ACTION_ANSWER = "com.mhrgl.aipbx.ACTION_ANSWER"
        const val ACTION_DECLINE = "com.mhrgl.aipbx.ACTION_DECLINE"
        const val ACTION_RECONNECT = "com.mhrgl.aipbx.ACTION_RECONNECT"
        const val ACTION_WATCHDOG = "com.mhrgl.aipbx.ACTION_WATCHDOG"

        fun start(context: Context) {
            val intent = Intent(context, PbxForegroundService::class.java)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        fun startWithAction(context: Context, action: String) {
            val intent = Intent(context, PbxForegroundService::class.java).apply {
                this.action = action
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }
    }
}
