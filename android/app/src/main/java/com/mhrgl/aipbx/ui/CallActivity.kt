package com.mhrgl.aipbx.ui

import android.app.AlertDialog
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.ServiceConnection
import android.media.AudioAttributes
import android.media.AudioDeviceCallback
import android.media.AudioDeviceInfo
import android.media.AudioFocusRequest
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import android.text.InputType
import android.util.Log
import android.view.View
import android.view.WindowManager
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.databinding.ActivityCallBinding
import com.mhrgl.aipbx.engine.SipEngineListener
import com.mhrgl.aipbx.model.CallStatus
import com.mhrgl.aipbx.model.ConnectionStatus
import com.mhrgl.aipbx.service.PbxForegroundService

class CallActivity : AppCompatActivity(), SipEngineListener {

    private lateinit var binding: ActivityCallBinding
    private lateinit var prefs: AppPreferences
    private var pbxService: PbxForegroundService? = null
    private var isBound = false

    private var isMuted = false
    private var isSpeakerOn = false
    private var isHold = false

    private var callSeconds = 0
    private val timerHandler = Handler(Looper.getMainLooper())
    private var timerRunnable: Runnable? = null

    private var proximityWakeLock: PowerManager.WakeLock? = null
    private lateinit var audioManager: AudioManager
    private var audioFocusRequest: AudioFocusRequest? = null
    private var toneGenerator: ToneGenerator? = null

    private val audioDeviceCallback = object : AudioDeviceCallback() {
        override fun onAudioDevicesAdded(addedDevices: Array<out AudioDeviceInfo>?) {
            Log.d("CallActivity", "Audio devices added, re-evaluating routing")
            if (!isSpeakerOn) {
                applyAudioRouting(false)
            }
        }

        override fun onAudioDevicesRemoved(removedDevices: Array<out AudioDeviceInfo>?) {
            Log.d("CallActivity", "Audio devices removed, re-evaluating routing")
            if (!isSpeakerOn) {
                applyAudioRouting(false)
            }
        }
    }

    private val serviceConnection = object : ServiceConnection {
        override fun onServiceConnected(name: ComponentName?, service: IBinder?) {
            val binder = service as PbxForegroundService.LocalBinder
            pbxService = binder.getService()
            pbxService?.registerListener(this@CallActivity)
            isBound = true

            val engine = pbxService?.engine
            if (engine != null) {
                binding.tvCallerName.text = engine.activeCallerName.ifEmpty { "Santral Çağrısı" }
                updateCallStateUI(engine.currentCallStatus)
            }
        }

        override fun onServiceDisconnected(name: ComponentName?) {
            pbxService = null
            isBound = false
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityCallBinding.inflate(layoutInflater)
        setContentView(binding.root)
        prefs = AppPreferences.getInstance(this)

        // Edge-to-edge WindowInsets destegi (M22)
        androidx.core.view.ViewCompat.setOnApplyWindowInsetsListener(binding.root) { view, insets ->
            val systemBars = insets.getInsets(androidx.core.view.WindowInsetsCompat.Type.systemBars())
            view.setPadding(systemBars.left, systemBars.top, systemBars.right, systemBars.bottom)
            insets
        }

        // Keep screen on during call setup
        window.addFlags(
            WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON or
                    WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                    WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
        )

        audioManager = getSystemService(Context.AUDIO_SERVICE) as AudioManager
        volumeControlStream = AudioManager.STREAM_VOICE_CALL

        try {
            toneGenerator = ToneGenerator(AudioManager.STREAM_VOICE_CALL, 80)
        } catch (e: Exception) {
            // ToneGenerator not available on this device
        }

        // Proximity sensor lock to turn off screen when at ear
        val pm = getSystemService(Context.POWER_SERVICE) as PowerManager
        if (pm.isWakeLockLevelSupported(PowerManager.PROXIMITY_SCREEN_OFF_WAKE_LOCK)) {
            proximityWakeLock = pm.newWakeLock(
                PowerManager.PROXIMITY_SCREEN_OFF_WAKE_LOCK,
                "AiPbxPhone:ProximityLock"
            )
        }

        audioManager.registerAudioDeviceCallback(audioDeviceCallback, null)

        setupControls()
        setupDtmfKeypad()
    }

    private var isRingbackPlaying = false

    private fun startRingback() {
        // Yalnizca giden cagri baglanirken/calarken calar; aktif gorusmede veya gelen cagrida calmaz
        val status = pbxService?.engine?.currentCallStatus
        if (status == CallStatus.ACTIVE || status == CallStatus.RINGING_INCOMING || status == CallStatus.ENDED || status == CallStatus.IDLE) {
            return
        }
        if (isRingbackPlaying) return
        isRingbackPlaying = true
        try {
            toneGenerator?.startTone(ToneGenerator.TONE_SUP_RINGTONE)
            Log.d("CallActivity", "Ringback tone started")
        } catch (e: Exception) {
            Log.w("CallActivity", "Could not start ringback tone", e)
        }
    }

    private fun stopRingback() {
        if (!isRingbackPlaying) return
        isRingbackPlaying = false
        try {
            toneGenerator?.stopTone()
            Log.d("CallActivity", "Ringback tone stopped")
        } catch (e: Exception) {
            Log.w("CallActivity", "Could not stop ringback tone", e)
        }
    }

    override fun onStart() {
        super.onStart()
        requestCallAudioFocus()
        bindService(Intent(this, PbxForegroundService::class.java), serviceConnection, Context.BIND_AUTO_CREATE)
    }

    override fun onStop() {
        super.onStop()
        stopRingback()
        proximityWakeLock?.let { if (it.isHeld) it.release() }
        if (isBound) {
            pbxService?.unregisterListener(this)
            unbindService(serviceConnection)
            isBound = false
        }
    }

    private fun requestCallAudioFocus() {
        try {
            audioManager.mode = AudioManager.MODE_IN_COMMUNICATION
            audioManager.isMicrophoneMute = false

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                val playbackAttributes = AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_VOICE_COMMUNICATION)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
                    .build()
                val focusReq = AudioFocusRequest.Builder(AudioManager.AUDIOFOCUS_GAIN_TRANSIENT_EXCLUSIVE)
                    .setAudioAttributes(playbackAttributes)
                    .setAcceptsDelayedFocusGain(true)
                    .setOnAudioFocusChangeListener { /* no-op */ }
                    .build()
                audioFocusRequest = focusReq
                audioManager.requestAudioFocus(focusReq)
            } else {
                @Suppress("DEPRECATION")
                audioManager.requestAudioFocus(
                    null,
                    AudioManager.STREAM_VOICE_CALL,
                    AudioManager.AUDIOFOCUS_GAIN_TRANSIENT_EXCLUSIVE
                )
            }

            // Audio Focus alindiktan HEMEN SONRA ses rotasi uygulanir (Varsayilan daima AHIZE!)
            applyAudioRouting(false)
        } catch (e: Exception) {
            Log.e("CallActivity", "Error requesting audio focus", e)
        }
    }

    private fun abandonCallAudioFocus() {
        try {
            stopRingback()
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                audioFocusRequest?.let { audioManager.abandonAudioFocusRequest(it) }
                audioFocusRequest = null
            } else {
                @Suppress("DEPRECATION")
                audioManager.abandonAudioFocus(null)
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                audioManager.clearCommunicationDevice()
            }
            @Suppress("DEPRECATION")
            audioManager.isSpeakerphoneOn = false
            audioManager.mode = AudioManager.MODE_NORMAL
        } catch (e: Exception) {
            Log.e("CallActivity", "Error abandoning audio focus", e)
        }
    }

    private fun setSpeakerphone(on: Boolean) {
        isSpeakerOn = on
        applyAudioRouting(on)
        updateButtonState(binding.ivSpeaker, isSpeakerOn)
    }

    private fun applyAudioRouting(speakerOn: Boolean) {
        try {
            audioManager.mode = AudioManager.MODE_IN_COMMUNICATION
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                if (speakerOn) {
                    val speakerDevice = audioManager.availableCommunicationDevices.firstOrNull {
                        it.type == AudioDeviceInfo.TYPE_BUILTIN_SPEAKER
                    }
                    if (speakerDevice != null) {
                        audioManager.setCommunicationDevice(speakerDevice)
                    } else {
                        audioManager.clearCommunicationDevice()
                    }
                    @Suppress("DEPRECATION")
                    audioManager.isSpeakerphoneOn = true
                } else {
                    val available = audioManager.availableCommunicationDevices
                    // Açık Öncelik Sırası (N1):
                    // 1. Bluetooth kulaklıklar
                    val targetDevice = available.firstOrNull {
                        it.type == AudioDeviceInfo.TYPE_BLE_HEADSET ||
                        it.type == AudioDeviceInfo.TYPE_BLUETOOTH_SCO
                    } ?:
                    // 2. Kablolu kulaklıklar
                    available.firstOrNull {
                        it.type == AudioDeviceInfo.TYPE_WIRED_HEADSET ||
                        it.type == AudioDeviceInfo.TYPE_WIRED_HEADPHONES ||
                        it.type == AudioDeviceInfo.TYPE_USB_HEADSET
                    } ?:
                    // 3. Dahili ahize
                    available.firstOrNull {
                        it.type == AudioDeviceInfo.TYPE_BUILTIN_EARPIECE
                    }

                    if (targetDevice != null) {
                        Log.d("CallActivity", "Selected communication device: type=${targetDevice.type} name=${targetDevice.productName}")
                        audioManager.setCommunicationDevice(targetDevice)
                    } else {
                        audioManager.clearCommunicationDevice()
                    }
                    @Suppress("DEPRECATION")
                    audioManager.isSpeakerphoneOn = false
                }
            } else {
                if (speakerOn) {
                    @Suppress("DEPRECATION")
                    if (audioManager.isBluetoothScoOn) {
                        audioManager.isBluetoothScoOn = false
                        audioManager.stopBluetoothSco()
                    }
                    @Suppress("DEPRECATION")
                    audioManager.isSpeakerphoneOn = true
                } else {
                    @Suppress("DEPRECATION")
                    audioManager.isSpeakerphoneOn = false
                    val hasBt = audioManager.getDevices(AudioManager.GET_DEVICES_OUTPUTS).any {
                        it.type == AudioDeviceInfo.TYPE_BLUETOOTH_SCO ||
                        it.type == AudioDeviceInfo.TYPE_BLE_HEADSET
                    }
                    if (hasBt) {
                        try {
                            @Suppress("DEPRECATION")
                            audioManager.startBluetoothSco()
                            @Suppress("DEPRECATION")
                            audioManager.isBluetoothScoOn = true
                        } catch (e: Exception) {
                            Log.w("CallActivity", "Failed to start Bluetooth SCO", e)
                        }
                    }
                }
            }
        } catch (e: Exception) {
            Log.e("CallActivity", "Error applying audio route (speaker=$speakerOn)", e)
            @Suppress("DEPRECATION")
            audioManager.isSpeakerphoneOn = speakerOn
        }

        // Proximity sensor kontrolü: Yalnızca dahili ahizedeyken ekran kararsın.
        // Hoparlörde veya Bluetooth/kablolu kulaklıktayken ekran açık kalmalı.
        val isUsingBuiltinEarpiece = !speakerOn && if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            audioManager.communicationDevice?.type == AudioDeviceInfo.TYPE_BUILTIN_EARPIECE
        } else {
            @Suppress("DEPRECATION")
            !audioManager.isBluetoothScoOn && !audioManager.isWiredHeadsetOn
        }

        if (isUsingBuiltinEarpiece) {
            proximityWakeLock?.let { if (!it.isHeld) it.acquire() }
        } else {
            proximityWakeLock?.let { if (it.isHeld) it.release() }
        }
    }

    private fun setupControls() {
        // MUTE
        binding.btnMute.setOnClickListener {
            isMuted = !isMuted
            pbxService?.engine?.toggleMute(isMuted)
            updateButtonState(binding.ivMute, isMuted)
        }

        // SPEAKER
        binding.btnSpeaker.setOnClickListener {
            setSpeakerphone(!isSpeakerOn)
        }

        // HOLD / UNHOLD
        binding.btnHold.setOnClickListener {
            isHold = !isHold
            pbxService?.engine?.toggleHold(isHold)
            if (isHold) {
                binding.tvHold.text = getString(R.string.btn_resume)
                binding.ivHold.setImageResource(R.drawable.ic_play)
                updateButtonState(binding.ivHold, true)
                binding.tvCallState.text = "Beklemede"
            } else {
                binding.tvHold.text = getString(R.string.btn_hold)
                binding.ivHold.setImageResource(R.drawable.ic_pause)
                updateButtonState(binding.ivHold, false)
                binding.tvCallState.text = getString(R.string.call_ongoing)
            }
        }

        // TRANSFER
        binding.btnTransfer.setOnClickListener {
            showTransferDialog()
        }

        // OPEN KEYPAD (DTMF)
        binding.btnKeypad.setOnClickListener {
            binding.tvDtmfDigits.text = ""
            binding.layoutNormalControls.visibility = View.GONE
            binding.layoutKeypadControls.visibility = View.VISIBLE
        }

        // HIDE KEYPAD
        binding.btnHideKeypad.setOnClickListener {
            binding.layoutKeypadControls.visibility = View.GONE
            binding.layoutNormalControls.visibility = View.VISIBLE
        }

        // HANGUP (Normal)
        binding.btnHangup.setOnClickListener {
            endCall()
        }

        // HANGUP (From DTMF Keypad)
        binding.btnHangupDtmf.setOnClickListener {
            endCall()
        }
    }

    private fun endCall() {
        stopRingback()
        stopCallTimer()
        pbxService?.engine?.hangupCall()
        finish()
    }

    private fun setupDtmfKeypad() {
        val dtmfMap = mapOf(
            binding.btnDtmf1 to "1",
            binding.btnDtmf2 to "2",
            binding.btnDtmf3 to "3",
            binding.btnDtmf4 to "4",
            binding.btnDtmf5 to "5",
            binding.btnDtmf6 to "6",
            binding.btnDtmf7 to "7",
            binding.btnDtmf8 to "8",
            binding.btnDtmf9 to "9",
            binding.btnDtmfStar to "*",
            binding.btnDtmf0 to "0",
            binding.btnDtmfPound to "#"
        )

        dtmfMap.forEach { (view, digit) ->
            view.setOnClickListener {
                onDtmfDigitClicked(digit)
            }
        }
    }

    private fun onDtmfDigitClicked(digit: String) {
        // Visual feedback
        val current = binding.tvDtmfDigits.text.toString()
        binding.tvDtmfDigits.text = current + digit

        // Audio feedback
        playDtmfTone(digit[0])

        // WebRTC SIP DTMF transmission
        pbxService?.engine?.sendDtmf(digit)
    }

    private fun playDtmfTone(digit: Char) {
        val tone = when (digit) {
            '1' -> ToneGenerator.TONE_DTMF_1
            '2' -> ToneGenerator.TONE_DTMF_2
            '3' -> ToneGenerator.TONE_DTMF_3
            '4' -> ToneGenerator.TONE_DTMF_4
            '5' -> ToneGenerator.TONE_DTMF_5
            '6' -> ToneGenerator.TONE_DTMF_6
            '7' -> ToneGenerator.TONE_DTMF_7
            '8' -> ToneGenerator.TONE_DTMF_8
            '9' -> ToneGenerator.TONE_DTMF_9
            '0' -> ToneGenerator.TONE_DTMF_0
            '*' -> ToneGenerator.TONE_DTMF_S
            '#' -> ToneGenerator.TONE_DTMF_P
            else -> ToneGenerator.TONE_DTMF_0
        }
        try {
            toneGenerator?.startTone(tone, 120)
        } catch (e: Exception) {
            // Ignored
        }
    }

    private fun showTransferDialog() {
        val editText = EditText(this).apply {
            hint = "Örn: 1002 veya 9998"
            inputType = InputType.TYPE_CLASS_PHONE
            textSize = 18f
            setPadding(40, 24, 40, 24)
        }

        val container = FrameLayout(this).apply {
            setPadding(40, 16, 40, 0)
            addView(editText)
        }

        AlertDialog.Builder(this)
            .setTitle(R.string.transfer_dialog_title)
            .setMessage(R.string.transfer_dialog_msg)
            .setView(container)
            .setPositiveButton(R.string.btn_transfer) { _, _ ->
                val target = editText.text.toString().trim()
                if (target.isNotEmpty()) {
                    val domain = prefs.domain?.ifEmpty { null } ?: try {
                        android.net.Uri.parse(prefs.serverUrl).host?.ifEmpty { null } ?: ""
                    } catch (e: Exception) { "" }
                    pbxService?.engine?.transferCall(target, domain)
                    Toast.makeText(
                        this,
                        getString(R.string.transfer_in_progress, target),
                        Toast.LENGTH_SHORT
                    ).show()
                    binding.tvCallState.text = "Aktarılıyor: $target"
                } else {
                    Toast.makeText(this, "Lütfen bir dahili numara girin", Toast.LENGTH_SHORT).show()
                }
            }
            .setNegativeButton(android.R.string.cancel, null)
            .show()
    }

    private fun updateButtonState(imageView: View, active: Boolean) {
        val color = if (active) {
            ContextCompat.getColor(this, R.color.primary)
        } else {
            ContextCompat.getColor(this, R.color.dialpad_letters)
        }
        imageView.backgroundTintList = android.content.res.ColorStateList.valueOf(
            if (active) ContextCompat.getColor(this, R.color.primary_dark) else 0xFF1E293B.toInt()
        )
    }

    private fun updateCallStateUI(status: CallStatus) {
        when (status) {
            CallStatus.CONNECTING -> {
                binding.tvCallState.text = getString(R.string.call_connecting)
                startRingback()
            }
            CallStatus.RINGING_OUTGOING -> {
                binding.tvCallState.text = getString(R.string.call_ringing)
                startRingback()
            }
            CallStatus.ACTIVE -> {
                stopRingback()
                applyAudioRouting(isSpeakerOn)
                if (isHold) {
                    binding.tvCallState.text = "Beklemede"
                } else {
                    binding.tvCallState.text = getString(R.string.call_ongoing)
                }
                startCallTimer()
            }
            CallStatus.ON_HOLD -> {
                stopRingback()
                binding.tvCallState.text = "Beklemede"
            }
            CallStatus.ENDED, CallStatus.IDLE -> {
                stopRingback()
                binding.tvCallState.text = getString(R.string.call_ended)
                stopCallTimer()
                Handler(Looper.getMainLooper()).postDelayed({ finish() }, 1000)
            }
            else -> {}
        }
    }

    private fun startCallTimer() {
        if (timerRunnable != null) return
        binding.tvCallDuration.visibility = View.VISIBLE
        callSeconds = 0

        timerRunnable = object : Runnable {
            override fun run() {
                callSeconds++
                val mins = callSeconds / 60
                val secs = callSeconds % 60
                binding.tvCallDuration.text = String.format("%02d:%02d", mins, secs)
                timerHandler.postDelayed(this, 1000)
            }
        }
        timerHandler.post(timerRunnable!!)
    }

    private fun stopCallTimer() {
        timerRunnable?.let { timerHandler.removeCallbacks(it) }
        timerRunnable = null
    }

    // --- SipEngineListener ---

    override fun onConnectionStatusChanged(status: ConnectionStatus) {}

    override fun onCallStatusChanged(status: CallStatus) {
        runOnUiThread { updateCallStateUI(status) }
    }

    override fun onIncomingCall(callerName: String, callerNumber: String) {}

    override fun onDestroy() {
        super.onDestroy()
        stopRingback()
        stopCallTimer()
        toneGenerator?.release()
        toneGenerator = null
        try {
            audioManager.unregisterAudioDeviceCallback(audioDeviceCallback)
        } catch (e: Exception) {
            Log.w("CallActivity", "Error unregistering audio device callback", e)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            audioManager.clearCommunicationDevice()
        } else {
            @Suppress("DEPRECATION")
            if (audioManager.isBluetoothScoOn) {
                audioManager.isBluetoothScoOn = false
                audioManager.stopBluetoothSco()
            }
        }
        abandonCallAudioFocus()
    }
}
