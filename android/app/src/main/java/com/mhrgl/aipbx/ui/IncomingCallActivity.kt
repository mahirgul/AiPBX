package com.mhrgl.aipbx.ui

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
import android.view.WindowManager
import android.widget.Toast
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import com.mhrgl.aipbx.databinding.ActivityIncomingCallBinding
import com.mhrgl.aipbx.engine.SipEngineListener
import com.mhrgl.aipbx.model.CallStatus
import com.mhrgl.aipbx.model.ConnectionStatus
import com.mhrgl.aipbx.service.PbxForegroundService

class IncomingCallActivity : AppCompatActivity(), SipEngineListener {

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
            }
        }

        override fun onServiceDisconnected(name: ComponentName?) {
            pbxService = null
            isBound = false
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.dark(android.graphics.Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.dark(android.graphics.Color.TRANSPARENT)
        )
        super.onCreate(savedInstanceState)
        wakeAndUnlockScreen()

        binding = ActivityIncomingCallBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Edge-to-edge WindowInsets desteği
        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { view, insets ->
            val systemBars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
            )
            view.setPadding(systemBars.left, systemBars.top, systemBars.right, systemBars.bottom)
            insets
        }

        val callerName = intent.getStringExtra(EXTRA_CALLER_NAME) ?: "Bilinmeyen Arayan"
        val callerNumber = intent.getStringExtra(EXTRA_CALLER_NUMBER) ?: ""

        binding.tvIncomingCallerName.text = callerName
        binding.tvIncomingCallerNumber.text = if (callerNumber.isNotEmpty()) "Dahili: $callerNumber" else "Santral Çağrısı"

        binding.btnAnswer.setOnClickListener {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
                performAnswer()
            } else {
                requestMicPermissionLauncher.launch(Manifest.permission.RECORD_AUDIO)
            }
        }

        binding.btnDecline.setOnClickListener {
            pbxService?.stopRinging()
            pbxService?.cancelIncomingCallNotification()
            if (pbxService != null) {
                pbxService?.engine?.rejectCall()
            } else {
                val declineIntent = Intent(this, PbxForegroundService::class.java).apply {
                    action = PbxForegroundService.ACTION_DECLINE
                }
                startService(declineIntent)
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
            Toast.makeText(this, "Aramayı cevaplayabilmek için mikrofon izni gereklidir.", Toast.LENGTH_LONG).show()
        }
    }

    private fun performAnswer() {
        pbxService?.stopRinging()
        pbxService?.engine?.answerCall()

        val callIntent = Intent(this, CallActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        }
        startActivity(callIntent)
        finish()
    }

    override fun onKeyDown(keyCode: Int, event: android.view.KeyEvent?): Boolean {
        when (keyCode) {
            android.view.KeyEvent.KEYCODE_VOLUME_DOWN,
            android.view.KeyEvent.KEYCODE_VOLUME_UP,
            android.view.KeyEvent.KEYCODE_POWER -> {
                // Kullanici ses/guc tusuna bastiginda aramayi reddetmeden sadece calan zili sustur
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
