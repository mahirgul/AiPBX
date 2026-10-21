package com.mhrgl.aipbx.ui

import com.mhrgl.aipbx.R
import android.Manifest
import android.app.KeyguardManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.ServiceConnection
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.os.IBinder
import android.view.View
import android.view.WindowManager
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.databinding.ActivityIncomingCallBinding
import com.mhrgl.aipbx.engine.SipEngineListener
import com.mhrgl.aipbx.model.CallStatus
import com.mhrgl.aipbx.model.ConnectionStatus
import com.mhrgl.aipbx.service.PbxForegroundService

class IncomingCallActivity : BaseActivity(), SipEngineListener {

    override val darkSystemBars = true

    private lateinit var binding: ActivityIncomingCallBinding
    private var pbxService: PbxForegroundService? = null
    private var isBound = false

    private val serviceConnection = object : ServiceConnection {
        override fun onServiceConnected(name: ComponentName?, service: IBinder?) {
            val binder = service as PbxForegroundService.LocalBinder
            pbxService = binder.getService()
            pbxService?.registerListener(this@IncomingCallActivity)
            isBound = true

            // If call already ended before we bound, close
            if (pbxService?.engine?.currentCallStatus != CallStatus.RINGING_INCOMING) {
                finish()
                return
            }
            // A video call: "answer with video" next to answer (video on for this user).
            val video = pbxService?.engine?.incomingHasVideo == true && AppPreferences.getInstance(this@IncomingCallActivity).videoLimits != null
            binding.layoutAnswerVideo.visibility = if (video) View.VISIBLE else View.GONE
        }

        override fun onServiceDisconnected(name: ComponentName?) {
            pbxService = null
            isBound = false
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        wakeAndUnlockScreen()

        binding = ActivityIncomingCallBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Edge-to-edge WindowInsets support
        applyEdgeToEdgeInsets(binding.root, consumeIme = false)

        val callerName = intent.getStringExtra(EXTRA_CALLER_NAME)?.takeIf { it.isNotBlank() } ?: getString(R.string.vm_unknown_caller)
        val callerNumber = intent.getStringExtra(EXTRA_CALLER_NUMBER)?.trim().orEmpty()

        binding.tvIncomingCallerName.text = callerName
        binding.tvIncomingCallerNumber.text = if (callerNumber.isNotEmpty()) getString(R.string.extension_label_caller, callerNumber) else getString(R.string.call_from_pbx)

        binding.btnAnswer.setOnClickListener {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
                performAnswer()
            } else {
                requestMicPermissionLauncher.launch(Manifest.permission.RECORD_AUDIO)
            }
        }

        binding.btnAnswerVideo.setOnClickListener {
            val needed = listOf(Manifest.permission.RECORD_AUDIO, Manifest.permission.CAMERA)
                .filter { ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED }
            if (needed.isEmpty()) performAnswer(withVideo = true) else requestVideoPermissionsLauncher.launch(needed.toTypedArray())
        }

        binding.btnDecline.setOnClickListener {
            pbxService?.stopRinging()
            pbxService?.cancelIncomingCallNotification()
            if (pbxService != null) {
                pbxService?.engine?.rejectCall()
            } else {
                // Not bound yet: the helper starts the service the way Android 8+ allows
                PbxForegroundService.startWithAction(this, PbxForegroundService.ACTION_DECLINE)
            }
            finish()
        }
    }

    private val requestMicPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { isGranted ->
        if (isGranted) {
            performAnswer()
        } else {
            Toast.makeText(this, getString(R.string.err_mic_permission_answer), Toast.LENGTH_LONG).show()
        }
    }

    // Camera refused: answer with audio only (the microphone is what a call needs).
    private val requestVideoPermissionsLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { result ->
        val mic = result[Manifest.permission.RECORD_AUDIO] ?: (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED)
        val cam = result[Manifest.permission.CAMERA] ?: (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED)
        when {
            !mic -> Toast.makeText(this, getString(R.string.err_mic_permission_answer), Toast.LENGTH_LONG).show()
            !cam -> {
                Toast.makeText(this, getString(R.string.err_camera_permission_video), Toast.LENGTH_LONG).show()
                performAnswer()
            }
            else -> performAnswer(withVideo = true)
        }
    }

    private fun performAnswer(withVideo: Boolean = false) {
        val service = pbxService
        if (service != null) {
            service.stopRinging()
            service.engine.answerCall(if (withVideo) AppPreferences.getInstance(this).videoLimits else null)

            val callIntent = Intent(this, CallActivity::class.java).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            }
            startActivity(callIntent)
            finish()
        } else {
            // Service not yet bound: dispatch ACTION_ANSWER / ACTION_ANSWER_VIDEO to PbxForegroundService
            val answerIntent = Intent(this, PbxForegroundService::class.java).apply {
                action = if (withVideo) PbxForegroundService.ACTION_ANSWER_VIDEO else PbxForegroundService.ACTION_ANSWER
            }
            try {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    startForegroundService(answerIntent)
                } else {
                    startService(answerIntent)
                }
            } catch (e: Exception) {
                try {
                    startService(answerIntent)
                } catch (ignored: Exception) {}
            }
            finish()
        }
    }

    override fun onKeyDown(keyCode: Int, event: android.view.KeyEvent?): Boolean {
        when (keyCode) {
            android.view.KeyEvent.KEYCODE_VOLUME_DOWN,
            android.view.KeyEvent.KEYCODE_VOLUME_UP,
            android.view.KeyEvent.KEYCODE_POWER -> {
                // When the user presses the volume/power key, only silence the ringing without rejecting the call
                pbxService?.stopRinging()
                return true
            }
        }
        return super.onKeyDown(keyCode, event)
    }

    private fun wakeAndUnlockScreen() {
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
            val km = getSystemService(Context.KEYGUARD_SERVICE) as KeyguardManager
            km.requestDismissKeyguard(this, null)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                        WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD or
                        WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
            )
        }
    }

    override fun onStart() {
        super.onStart()
        bindService(Intent(this, PbxForegroundService::class.java), serviceConnection, Context.BIND_AUTO_CREATE)
    }

    override fun onStop() {
        super.onStop()
        if (isBound) {
            pbxService?.unregisterListener(this)
            unbindService(serviceConnection)
            isBound = false
        }
    }

    // --- SipEngineListener ---

    override fun onConnectionStatusChanged(status: ConnectionStatus) {}

    override fun onCallStatusChanged(status: CallStatus) {
        // If caller hung up or call cancelled, dismiss incoming call screen
        if (status != CallStatus.RINGING_INCOMING) {
            runOnUiThread { finish() }
        }
    }

    override fun onIncomingCall(callerName: String, callerNumber: String) {}

    companion object {
        const val EXTRA_CALLER_NAME = "extra_caller_name"
        const val EXTRA_CALLER_NUMBER = "extra_caller_number"
    }
}
