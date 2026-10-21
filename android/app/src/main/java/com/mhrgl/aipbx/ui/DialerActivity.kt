package com.mhrgl.aipbx.ui

import com.mhrgl.aipbx.util.BatteryPrompt
import com.mhrgl.aipbx.util.FullScreenCallPrompt
import com.google.android.material.snackbar.Snackbar
import androidx.recyclerview.widget.ItemTouchHelper
import com.mhrgl.aipbx.util.L10n
import android.Manifest
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.ServiceConnection
import android.content.pm.PackageManager
import android.content.res.ColorStateList
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.IBinder
import android.os.PowerManager
import android.provider.ContactsContract
import android.view.LayoutInflater
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsAnimationCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.widget.doAfterTextChanged
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.BuildConfig
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.data.ChatEventListener
import com.mhrgl.aipbx.data.ChatUploadPrep
import com.mhrgl.aipbx.data.ChatWebSocketManager
import com.mhrgl.aipbx.databinding.ActivityDialerBinding
import com.mhrgl.aipbx.databinding.DialogGroupInfoBinding
import com.mhrgl.aipbx.databinding.DialogNewGroupBinding
import com.mhrgl.aipbx.engine.SipEngineListener
import com.mhrgl.aipbx.model.CallStatus
import com.mhrgl.aipbx.model.ChatConversation
import com.mhrgl.aipbx.model.ChatMessage
import com.mhrgl.aipbx.model.ChatUploadResponse
import com.mhrgl.aipbx.model.ConnectionStatus
import com.mhrgl.aipbx.model.ContactItem
import com.mhrgl.aipbx.service.PbxForegroundService
import com.mhrgl.aipbx.util.SamsungPowerManagerHelper
import com.mhrgl.aipbx.util.SearchUtils
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.io.FileOutputStream

class DialerActivity : BaseActivity(), SipEngineListener, ChatEventListener {

    private lateinit var binding: ActivityDialerBinding
    private lateinit var prefs: AppPreferences
    private val apiClient by lazy { ApiClient { prefs } }

    private var pbxService: PbxForegroundService? = null
    private var isBound = false

    private lateinit var historyAdapter: CallHistoryAdapter
    private lateinit var contactsAdapter: ContactsAdapter
    private lateinit var chatAdapter: ChatConversationAdapter
    private val allConversations = mutableListOf<ChatConversation>()
    private val chatCorporateContacts = mutableListOf<ContactItem>()

    // Embedded Chat Room
    private lateinit var chatMessageAdapter: ChatMessageAdapter
    private var currentChatConvId: Int = 0
    private var currentChatTargetExt: String = ""
    private var currentChatTargetName: String = ""
    private var currentChatIsGroup: Boolean = false
    private var currentChatGroupDetails: ChatConversation? = null
    private var chatPendingUpload: ChatUploadResponse? = null

    private val pickChatDocumentLauncher = registerForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) handleChatPickedUri(uri, "file")
    }

    private val pickChatPhotoLauncher = registerForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) handleChatPickedUri(uri, "image")
    }

    // Camera: a shot photo goes through the same prepare/upload flow as one from the gallery.
    private val takeChatPhotoLauncher = registerForActivityResult(ActivityResultContracts.TakePicture()) { ok ->
        if (ok) handleChatPickedUri(ChatUploadPrep.cameraUri(this), "image")
    }

    // Since the manifest declares the CAMERA permission, Android requires it
    // to be granted before the camera app can be opened.
    private val chatCameraPermissionLauncher = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) takeChatPhotoLauncher.launch(ChatUploadPrep.cameraUri(this))
        else Toast.makeText(this, getString(R.string.err_camera_permission), Toast.LENGTH_LONG).show()
    }

    private fun showChatPhotoSourceMenu(anchor: View) {
        val popup = androidx.appcompat.widget.PopupMenu(this, anchor)
        popup.menu.add(0, 1, 0, getString(R.string.menu_take_photo))
        popup.menu.add(0, 2, 1, getString(R.string.menu_pick_gallery))
        popup.setOnMenuItemClickListener { item ->
            when (item.itemId) {
                1 -> {
                    val granted = androidx.core.content.ContextCompat.checkSelfPermission(
                        this, android.Manifest.permission.CAMERA
                    ) == android.content.pm.PackageManager.PERMISSION_GRANTED
                    if (granted) takeChatPhotoLauncher.launch(ChatUploadPrep.cameraUri(this))
                    else chatCameraPermissionLauncher.launch(android.Manifest.permission.CAMERA)
                    true
                }
                2 -> { pickChatPhotoLauncher.launch("image/*"); true }
                else -> false
            }
        }
        popup.show()
    }

    private var currentFilter = "all"
    private enum class ChatFilter { ALL, DIRECT, GROUP }
    private var chatFilterMode = ChatFilter.ALL
    private var isDndEnabled = false
    private var currentForwardAlways = ""
    private var currentForwardBusy = ""
    private var currentForwardNoAnswer = ""
    private var currentNoAnswerTimeout = 20
    private val timeoutOptions = listOf(10, 15, 20, 25, 30, 45)
    private var isDeviceContactsSelected = false
    private var corporateContactsList: List<ContactItem> = emptyList()
    private var deviceContactsList: List<ContactItem> = emptyList()

    private val serviceConnection = object : ServiceConnection {
        override fun onServiceConnected(name: ComponentName?, service: IBinder?) {
            val binder = service as PbxForegroundService.LocalBinder
            pbxService = binder.getService()
            pbxService?.registerListener(this@DialerActivity)
            isBound = true

            updateConnectionUI(pbxService?.engine?.currentConnectionStatus ?: ConnectionStatus.DISCONNECTED)
        }

        override fun onServiceDisconnected(name: ComponentName?) {
            pbxService = null
            isBound = false
        }
    }

    private val requestPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        val recordAudioGranted = permissions[Manifest.permission.RECORD_AUDIO] ?: false
        if (!recordAudioGranted) {
            Toast.makeText(this, getString(R.string.err_mic_permission_call), Toast.LENGTH_LONG).show()
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putString("saved_tab", currentTab.name)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = AppPreferences.getInstance(this)

        if (!prefs.isLoggedIn) {
            startActivity(Intent(this, ServerSetupActivity::class.java))
            finish()
            return
        }

        binding = ActivityDialerBinding.inflate(layoutInflater)
        setContentView(binding.root)
        onBackPressedDispatcher.addCallback(this, backCallback)

        // Edge-to-edge WindowInsets support
        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { view, insets ->
            val systemBars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
            )
            val ime = insets.getInsets(WindowInsetsCompat.Type.ime())
            val bottom = maxOf(systemBars.bottom, ime.bottom)
            view.setPadding(systemBars.left, systemBars.top, systemBars.right, bottom)
            insets
        }

        ViewCompat.setWindowInsetsAnimationCallback(
            binding.root,
            object : WindowInsetsAnimationCompat.Callback(DISPATCH_MODE_STOP) {
                override fun onProgress(
                    insets: WindowInsetsCompat,
                    runningAnimations: MutableList<WindowInsetsAnimationCompat>
                ): WindowInsetsCompat {
                    val ime = insets.getInsets(WindowInsetsCompat.Type.ime())
                    val systemBars = insets.getInsets(
                        WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
                    )
                    val bottom = maxOf(systemBars.bottom, ime.bottom)
                    binding.root.setPadding(systemBars.left, systemBars.top, systemBars.right, bottom)
                    return insets
                }
            }
        )

        setupUserHeader()
        setupDialpad()
        setupBottomNav()
        setupHistoryTab()
        setupContactsTab()
        setupChatTab()
        setupFeaturesTab()
        savedInstanceState?.getString("saved_tab")?.let { tabName ->
            runCatching { Tab.valueOf(tabName) }.getOrNull()?.let { switchTab(it) }
        }
        checkPermissions()
        // One system request per start: the full-screen explanation waits for the next one.
        if (!BatteryPrompt.askOnce(this)) {
            FullScreenCallPrompt.askOnce(this)
        }

        // Background initial sync
        applyVideoEnabled()
        syncFeatures()
        syncDeviceToken()

        handleIncomingIntent(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleIncomingIntent(intent)
    }

    private fun handleIncomingIntent(intent: Intent?) {
        if (intent == null) return
        val convId = intent.getIntExtra(ChatKeys.EXTRA_CONV_ID, 0)
        val targetExt = intent.getStringExtra(ChatKeys.EXTRA_TARGET_EXT) ?: ""
        val targetName = intent.getStringExtra(ChatKeys.EXTRA_TARGET_NAME)
        val isGroup = intent.getBooleanExtra(ChatKeys.EXTRA_IS_GROUP, false)
        val dialNum = intent.getStringExtra("extra_dial_number")

        if (convId > 0 || targetExt.isNotEmpty()) {
            switchTab(Tab.CHAT)
            openChatRoom(convId, targetExt, targetName, isGroup)
        } else if (!dialNum.isNullOrEmpty()) {
            switchTab(Tab.DIALER)
            setDialNumber(dialNum)
        }
    }


    override fun onStart() {
        super.onStart()
        val intent = Intent(this, PbxForegroundService::class.java)
        bindService(intent, serviceConnection, Context.BIND_AUTO_CREATE)
    }

    override fun onResume() {
        super.onResume()
        ChatWebSocketManager.instance.addListener(this)
        updateFullScreenCallButton()
        refreshVoicemailBadge()

        if (currentTab == Tab.HISTORY) {
            loadCallHistory(currentFilter)
        } else if (currentTab == Tab.CHAT) {
            if (isChatRoomOpen()) {
                ChatKeys.activeConversationId = currentChatConvId
                val nm = getSystemService(Context.NOTIFICATION_SERVICE) as? android.app.NotificationManager
                nm?.cancel(10000 + (currentChatConvId % 1000))
                loadChatRoomMessages()
            } else {
                loadConversations()
            }
        }
        updateChatUnreadBadge()

        val sUrl = prefs.serverUrl
        val token = prefs.token
        if (!sUrl.isNullOrEmpty() && !token.isNullOrEmpty()) {
            ChatWebSocketManager.instance.connect(sUrl, token)
        }

        val sleptInBackground = prefs.hasSleepingWarning
        prefs.hasSleepingWarning = false
        // At most once a week, and never once the app is exempt from battery optimisation.
        val pm = getSystemService(Context.POWER_SERVICE) as? PowerManager
        val exempt = Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && pm?.isIgnoringBatteryOptimizations(packageName) == true
        val now = System.currentTimeMillis()
        if (sleptInBackground && !exempt && now - prefs.sleepingWarningShownAt > 7 * 24 * 3600 * 1000L) {
            prefs.sleepingWarningShownAt = now
            AlertDialog.Builder(this)
                .setTitle(getString(R.string.bg_restricted_title))
                .setMessage(getString(R.string.bg_restricted_msg))
                .setPositiveButton(getString(R.string.btn_edit_settings)) { _, _ ->
                    SamsungPowerManagerHelper.openBatterySettings(this)
                }
                .setNegativeButton(getString(R.string.btn_ok), null)
                .show()
        }
    }

    override fun onPause() {
        super.onPause()
        sendChatTypingState(false)
        if (ChatKeys.activeConversationId == currentChatConvId) {
            ChatKeys.activeConversationId = 0
        }
    }

    override fun onStop() {
        super.onStop()
        if (isBound) {
            pbxService?.unregisterListener(this)
            unbindService(serviceConnection)
            isBound = false
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        if (ChatKeys.activeConversationId == currentChatConvId) {
            ChatKeys.activeConversationId = 0
        }
        ChatWebSocketManager.instance.removeListener(this)
    }

    /** Number of new voicemail messages on the button in the header. */
    private fun refreshVoicemailBadge() {
        val token = prefs.token ?: return
        lifecycleScope.launch {
            apiClient.getVoicemail(prefs.serverUrl, token).onSuccess { res ->
                val n = res.newCount
                binding.tvVoicemailBadge.text = if (n > 99) "99+" else n.toString()
                binding.tvVoicemailBadge.visibility = if (n > 0) View.VISIBLE else View.GONE
            }
        }
    }

    /**
     * Back closes the open chat, then goes to the keypad, then leaves. With
     * targetSdk 36 (predictive back) onBackPressed() is no longer called, so
     * this runs from the dispatcher.
     */
    private val backCallback = object : OnBackPressedCallback(true) {
        override fun handleOnBackPressed() {
            when {
                currentTab == Tab.CHAT && isChatRoomOpen() -> closeChatRoom()
                currentTab != Tab.DIALER -> switchTab(Tab.DIALER)
                else -> {
                    isEnabled = false
                    onBackPressedDispatcher.onBackPressed()
                    isEnabled = true
                }
            }
        }
    }

    // ================= HEADER & LOGOUT =================

    private fun setupUserHeader() {
        val ext = prefs.extension ?: "--"
        val name = prefs.fullName ?: (prefs.username ?: "")
        // Number and name only ("19000 (Mahir)"): the "Extension:" label took room without telling anything.
        binding.tvUserExt.text = if (name.isNotBlank() && name != ext) "$ext ($name)" else ext

        binding.btnVoicemail.setOnClickListener { VoicemailActivity.start(this) }

        binding.btnLogout.setOnClickListener {
            AlertDialog.Builder(this)
                .setTitle(getString(R.string.logout_title))
                .setMessage(getString(R.string.logout_confirm))
                .setPositiveButton(getString(R.string.btn_logout)) { _, _ ->
                    com.mhrgl.aipbx.service.FcmHelper.unregisterTokenFromServer(this)
                    com.mhrgl.aipbx.data.ChatWebSocketManager.instance.disconnect()
                    pbxService?.engine?.destroy()
                    prefs.clearAuth()
                    stopService(Intent(this, PbxForegroundService::class.java))
                    try {
                        java.io.File(cacheDir, "attachments").deleteRecursively()
                    } catch (e: Exception) { /* ignore */ }
                    startActivity(Intent(this, ServerSetupActivity::class.java))
                    finish()
                }
                .setNegativeButton(getString(R.string.btn_cancel), null)
                .show()
        }
    }

    private fun updateChatUnreadBadge() {
        val sUrl = prefs.serverUrl ?: return
        val token = prefs.token ?: return
        lifecycleScope.launch {
            val res = apiClient.getChatConversations(sUrl, token)
            res.onSuccess { list ->
                val totalUnread = list.sumOf { it.unreadCount }
                if (totalUnread > 0) {
                    binding.tvChatBadge.visibility = View.VISIBLE
                    binding.tvChatBadge.text = if (totalUnread > 9) "9+" else totalUnread.toString()
                } else {
                    binding.tvChatBadge.visibility = View.GONE
                }
            }
        }
    }

    // ================= BOTTOM NAVIGATION =================

    private enum class Tab { DIALER, HISTORY, CONTACTS, CHAT, FEATURES }
    private var currentTab: Tab = Tab.DIALER

    private fun setupBottomNav() {
        binding.tabDialer.setOnClickListener { switchTab(Tab.DIALER) }
        binding.tabHistory.setOnClickListener {
            switchTab(Tab.HISTORY)
            loadCallHistory(currentFilter)
        }
        binding.tabContacts.setOnClickListener {
            switchTab(Tab.CONTACTS)
            if (isDeviceContactsSelected) {
                loadDeviceContacts()
            } else {
                loadCorporateContacts()
            }
        }
        binding.tabChat.setOnClickListener {
            if (currentTab == Tab.CHAT && isChatRoomOpen()) {
                closeChatRoom()
            } else {
                switchTab(Tab.CHAT)
                if (!isChatRoomOpen()) {
                    loadConversations()
                }
            }
        }
        binding.tabFeatures.setOnClickListener {
            switchTab(Tab.FEATURES)
            syncFeatures()
        }
    }

    private fun switchTab(tab: Tab) {
        currentTab = tab
        val colorActive = ContextCompat.getColor(this, R.color.primary)
        val colorInactive = ContextCompat.getColor(this, R.color.text_secondary)

        // Reset all tabs
        binding.ivTabDialer.setColorFilter(colorInactive)
        binding.tvTabDialer.setTextColor(colorInactive)
        binding.ivTabHistory.setColorFilter(colorInactive)
        binding.tvTabHistory.setTextColor(colorInactive)
        binding.ivTabContacts.setColorFilter(colorInactive)
        binding.tvTabContacts.setTextColor(colorInactive)
        binding.ivTabChat.setColorFilter(colorInactive)
        binding.tvTabChat.setTextColor(colorInactive)
        binding.ivTabFeatures.setColorFilter(colorInactive)
        binding.tvTabFeatures.setTextColor(colorInactive)

        binding.layoutDialpad.visibility = View.GONE
        binding.layoutHistory.visibility = View.GONE
        binding.layoutContacts.visibility = View.GONE
        binding.layoutChat.visibility = View.GONE
        binding.layoutFeatures.visibility = View.GONE

        if (tab != Tab.CHAT) {
            ChatKeys.activeConversationId = 0
        }

        when (tab) {
            Tab.DIALER -> {
                binding.layoutDialpad.visibility = View.VISIBLE
                binding.ivTabDialer.setColorFilter(colorActive)
                binding.tvTabDialer.setTextColor(colorActive)
            }
            Tab.HISTORY -> {
                binding.layoutHistory.visibility = View.VISIBLE
                binding.ivTabHistory.setColorFilter(colorActive)
                binding.tvTabHistory.setTextColor(colorActive)
            }
            Tab.CONTACTS -> {
                binding.layoutContacts.visibility = View.VISIBLE
                binding.ivTabContacts.setColorFilter(colorActive)
                binding.tvTabContacts.setTextColor(colorActive)
            }
            Tab.CHAT -> {
                binding.layoutChat.visibility = View.VISIBLE
                binding.ivTabChat.setColorFilter(colorActive)
                binding.tvTabChat.setTextColor(colorActive)
                if (isChatRoomOpen()) {
                    ChatKeys.activeConversationId = currentChatConvId
                }
            }
            Tab.FEATURES -> {
                binding.layoutFeatures.visibility = View.VISIBLE
                binding.ivTabFeatures.setColorFilter(colorActive)
                binding.tvTabFeatures.setTextColor(colorActive)
            }
        }

        binding.bottomNav.visibility = if (tab == Tab.CHAT && isChatRoomOpen()) View.GONE else View.VISIBLE
    }

    // ================= TAB 1: DIALPAD =================

    private fun setupDialpad() {
        val digitButtons = mapOf(
            binding.btnDigit1 to "1",
            binding.btnDigit2 to "2",
            binding.btnDigit3 to "3",
            binding.btnDigit4 to "4",
            binding.btnDigit5 to "5",
            binding.btnDigit6 to "6",
            binding.btnDigit7 to "7",
            binding.btnDigit8 to "8",
            binding.btnDigit9 to "9",
            binding.btnDigit0 to "0",
            binding.btnDigitStar to "*",
            binding.btnDigitPound to "#"
        )

        digitButtons.forEach { (layout, digit) ->
            layout.setOnClickListener {
                appendDigit(digit)
            }
        }

        binding.btnDigit0.setOnLongClickListener {
            appendDigit("+")
            true
        }

        // Deletes the selection, else the character before the cursor.
        binding.btnBackspace.setOnClickListener {
            val field = binding.tvDigits
            val text = field.text ?: return@setOnClickListener
            val start = minOf(field.selectionStart, field.selectionEnd).coerceAtLeast(0)
            val end = maxOf(field.selectionStart, field.selectionEnd).coerceAtLeast(0)
            when {
                start != end -> text.delete(start, end)
                start > 0 -> text.delete(start - 1, start)
            }
            updateBackspaceVisibility()
        }

        binding.btnBackspace.setOnLongClickListener {
            setDialNumber("")
            true
        }

        // The keypad types; tapping the field only places the cursor (no system keyboard).
        binding.tvDigits.showSoftInputOnFocus = false
        // Typed or pasted text keeps only what can be dialled ("+49 (170) 123-45-67" → +491701234567).
        binding.tvDigits.filters = arrayOf(android.text.InputFilter { source, start, end, _, _, _ ->
            val kept = source.subSequence(start, end).filter { it.isDigit() || it in "*#+" }
            if (kept.length == end - start) null else kept
        })
        binding.tvDigits.doAfterTextChanged { updateBackspaceVisibility() }
        binding.tvDigits.setOnEditorActionListener { _, _, _ ->
            binding.btnCall.performClick()
            true
        }

        binding.btnCall.setOnClickListener {
            val target = binding.tvDigits.text.toString().trim()
            if (target.isEmpty()) {
                Toast.makeText(this, getString(R.string.err_enter_number), Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }
            initiateCall(target)
        }
    }

    /** Types at the cursor (replacing a selection), like a phone app's keypad. */
    private fun appendDigit(digit: String) {
        val field = binding.tvDigits
        val text = field.text ?: return
        if (!field.hasFocus() && field.selectionStart <= 0) field.setSelection(text.length)
        val start = minOf(field.selectionStart, field.selectionEnd).coerceAtLeast(0)
        val end = maxOf(field.selectionStart, field.selectionEnd).coerceAtLeast(0)
        text.replace(start, end, digit)
        updateBackspaceVisibility()
    }

    private fun setDialNumber(number: String) {
        binding.tvDigits.setText(number)
        binding.tvDigits.setSelection(binding.tvDigits.text?.length ?: 0)
        updateBackspaceVisibility()
    }

    private fun updateBackspaceVisibility() {
        binding.btnBackspace.visibility = if (binding.tvDigits.text.isNotEmpty()) View.VISIBLE else View.INVISIBLE
    }

    private fun applyVideoEnabled() {
        val on = prefs.videoLimits != null
        if (::contactsAdapter.isInitialized) contactsAdapter.videoEnabled = on
        if (::historyAdapter.isInitialized) historyAdapter.videoEnabled = on
    }

    /** A video call waiting for the camera permission. */
    private var pendingVideoCall: Pair<String, String?>? = null

    // Camera refused: the call goes out with audio only.
    private val cameraPermissionLauncher = registerForActivityResult(
        androidx.activity.result.contract.ActivityResultContracts.RequestPermission()
    ) { granted ->
        val call = pendingVideoCall ?: return@registerForActivityResult
        pendingVideoCall = null
        if (!granted) Toast.makeText(this, getString(R.string.err_camera_permission_video), Toast.LENGTH_LONG).show()
        initiateCall(call.first, call.second, withVideo = granted)
    }

    private fun initiateCall(targetNumber: String, displayName: String? = null, withVideo: Boolean = false) {
        if (withVideo && ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            pendingVideoCall = targetNumber to displayName
            cameraPermissionLauncher.launch(Manifest.permission.CAMERA)
            return
        }
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            Toast.makeText(this, getString(R.string.err_mic_permission_call), Toast.LENGTH_LONG).show()
            requestPermissionLauncher.launch(arrayOf(Manifest.permission.RECORD_AUDIO))
            return
        }

        val service = pbxService
        if (service == null || service.engine.currentConnectionStatus != ConnectionStatus.CONNECTED) {
            Toast.makeText(this, getString(R.string.err_not_connected), Toast.LENGTH_SHORT).show()
            return
        }

        var cleanNumber = targetNumber.replace(Regex("[^0-9*#+]"), "")
        if (cleanNumber.startsWith("+") && !prefs.sendPlusPrefix) {
            cleanNumber = "00" + cleanNumber.substring(1)
        }

        if (cleanNumber.isEmpty()) {
            Toast.makeText(this, getString(R.string.err_enter_valid_number), Toast.LENGTH_SHORT).show()
            return
        }

        setDialNumber(cleanNumber)

        val domain = prefs.domain?.ifEmpty { null } ?: try {
            android.net.Uri.parse(prefs.serverUrl).host?.ifEmpty { null } ?: ""
        } catch (e: Exception) { "" }
        service.engine.makeCall(
            targetNumber = cleanNumber,
            domain = domain,
            turnUrl = prefs.turnUrl,
            turnUser = prefs.turnUsername,
            turnPass = prefs.turnCredential,
            displayName = displayName,
            video = if (withVideo) prefs.videoLimits else null
        )

        val callIntent = Intent(this, CallActivity::class.java)
        startActivity(callIntent)
    }

    // ================= TAB 2: CALL HISTORY =================

    private fun setupHistoryTab() {
        historyAdapter = CallHistoryAdapter(
            onCallClick = { targetNumber, partyName -> initiateCall(targetNumber, partyName) },
            onVideoCallClick = { targetNumber, partyName -> initiateCall(targetNumber, partyName, withVideo = true) },
            onChatClick = { targetExt, partyName ->
                switchTab(Tab.CHAT)
                openChatRoom(0, targetExt, partyName ?: targetExt, false)
            }
        )
        binding.rvCallHistory.layoutManager = LinearLayoutManager(this)
        binding.rvCallHistory.adapter = historyAdapter

        binding.btnRefreshHistory.setOnClickListener {
            loadCallHistory(currentFilter)
        }

        // #10: swipe a call away / clear the list. Only the user's own list
        // changes; the server keeps the call records. Older servers send no
        // call_key: then rows cannot be swiped and the button stays hidden.
        ItemTouchHelper(object : ItemTouchHelper.SimpleCallback(0, ItemTouchHelper.LEFT or ItemTouchHelper.RIGHT) {
            override fun onMove(rv: RecyclerView, vh: RecyclerView.ViewHolder, target: RecyclerView.ViewHolder) = false

            override fun getSwipeDirs(rv: RecyclerView, vh: RecyclerView.ViewHolder): Int =
                if (historyAdapter.itemAt(vh.adapterPosition)?.callKey.isNullOrEmpty()) 0
                else super.getSwipeDirs(rv, vh)

            override fun onSwiped(vh: RecyclerView.ViewHolder, direction: Int) {
                val pos = vh.adapterPosition
                val item = historyAdapter.removeAt(pos) ?: return
                val key = item.callKey ?: return
                if (historyAdapter.itemCount == 0) binding.tvEmptyHistory.visibility = View.VISIBLE
                Snackbar.make(binding.rvCallHistory, getString(R.string.history_removed), Snackbar.LENGTH_LONG)
                    .setAction(getString(R.string.history_undo)) {
                        historyAdapter.insertAt(pos, item)
                        binding.tvEmptyHistory.visibility = View.GONE
                    }
                    .addCallback(object : Snackbar.Callback() {
                        override fun onDismissed(bar: Snackbar?, event: Int) {
                            if (event == DISMISS_EVENT_ACTION) return
                            hideCallsOnServer(listOf(key))
                        }
                    })
                    .show()
            }
        }).attachToRecyclerView(binding.rvCallHistory)

        binding.btnClearHistory.setOnClickListener {
            AlertDialog.Builder(this)
                .setTitle(getString(R.string.history_clear))
                .setMessage(getString(R.string.history_clear_msg))
                .setPositiveButton(getString(R.string.history_clear_btn)) { _, _ -> hideCallsOnServer(null) }
                .setNegativeButton(getString(R.string.ui_cancel), null)
                .show()
        }

        binding.btnFilterAll.setOnClickListener { setHistoryFilter("all") }
        binding.btnFilterMissed.setOnClickListener { setHistoryFilter("missed") }
        binding.btnFilterIn.setOnClickListener { setHistoryFilter("in") }
        binding.btnFilterOut.setOnClickListener { setHistoryFilter("out") }
    }

    private fun setHistoryFilter(filter: String) {
        currentFilter = filter

        val colorWhite = ContextCompat.getColor(this, R.color.card_bg)
        val colorSecondaryText = ContextCompat.getColor(this, R.color.text_secondary)

        listOf(binding.btnFilterAll, binding.btnFilterMissed, binding.btnFilterIn, binding.btnFilterOut).forEach { btn ->
            btn.backgroundTintList = ContextCompat.getColorStateList(this, R.color.dialpad_btn_bg)
            btn.setTextColor(colorSecondaryText)
        }

        val activeBtn = when (filter) {
            "missed" -> binding.btnFilterMissed
            "in" -> binding.btnFilterIn
            "out" -> binding.btnFilterOut
            else -> binding.btnFilterAll
        }
        activeBtn.backgroundTintList = ContextCompat.getColorStateList(this, R.color.primary)
        activeBtn.setTextColor(colorWhite)

        loadCallHistory(filter)
    }

    /** [callKeys] = null clears the whole history. */
    private fun hideCallsOnServer(callKeys: List<String>?) {
        val token = prefs.token ?: return
        lifecycleScope.launch {
            apiClient.hideCalls(prefs.serverUrl, token, callKeys)
                .onSuccess {
                    if (callKeys == null) {
                        Toast.makeText(this@DialerActivity, getString(R.string.history_cleared), Toast.LENGTH_SHORT).show()
                    }
                    loadCallHistory(currentFilter)
                }
                .onFailure { err ->
                    Toast.makeText(this@DialerActivity, getString(R.string.history_remove_failed, err.message ?: ""), Toast.LENGTH_SHORT).show()
                    loadCallHistory(currentFilter)
                }
        }
    }

    /** Which history rows get the chat button: colleagues from the company directory, loaded once if needed. */
    private fun updateHistoryChatExtensions() {
        val own = prefs.extension ?: ""
        fun apply() {
            historyAdapter.chatExtensions = corporateContactsList.map { it.extension }.filter { it.isNotEmpty() && it != own }.toSet()
        }
        if (corporateContactsList.isNotEmpty()) {
            apply()
            return
        }
        val token = prefs.token ?: return
        lifecycleScope.launch {
            apiClient.getContacts(prefs.serverUrl, token).onSuccess { response ->
                if (corporateContactsList.isEmpty()) corporateContactsList = response.contacts ?: emptyList()
                apply()
            }
        }
    }

    private fun loadCallHistory(filter: String) {
        val token = prefs.token ?: return
        val baseUrl = prefs.serverUrl

        binding.pbHistory.visibility = View.VISIBLE
        binding.tvEmptyHistory.visibility = View.GONE

        lifecycleScope.launch {
            val result = apiClient.getCallHistory(baseUrl, token, filter = filter)
            binding.pbHistory.visibility = View.GONE

            result.onSuccess { response ->
                val list = response.calls ?: emptyList()
                historyAdapter.submitList(list)
                updateHistoryChatExtensions()
                // Hiding needs server 1.5.3+ (call_key); the filter view may be empty, so keep it once known.
                if (list.any { !it.callKey.isNullOrEmpty() }) {
                    binding.btnClearHistory.visibility = View.VISIBLE
                }

                if (list.isEmpty()) {
                    binding.tvEmptyHistory.visibility = View.VISIBLE
                } else {
                    binding.tvEmptyHistory.visibility = View.GONE
                }

                response.stats?.let { s ->
                    val mins = s.totalBillsec / 60
                    binding.tvHistoryStats.text = getString(R.string.history_stats, s.totalCalls, s.missedCalls, mins)
                }
            }.onFailure { err ->
                // Signed out meanwhile (session rejected): the sign-in screen explains it.
                if (!prefs.isLoggedIn) return@onFailure
                Toast.makeText(this@DialerActivity, getString(R.string.err_history_load, err.message), Toast.LENGTH_SHORT).show()
                binding.tvEmptyHistory.visibility = View.VISIBLE
            }
        }
    }

    // ================= TAB 3: CONTACTS =================

    private fun setupContactsTab() {
        contactsAdapter = ContactsAdapter(
            onCallClick = { targetExt, contactName -> initiateCall(targetExt, contactName) },
            onVideoCallClick = { targetExt, contactName -> initiateCall(targetExt, contactName, withVideo = true) },
            onChatClick = { targetExt, contactName ->
                if (targetExt != (prefs.extension ?: "")) {
                    switchTab(Tab.CHAT)
                    openChatRoom(0, targetExt, contactName, false)
                }
            }
        )
        binding.rvContacts.layoutManager = LinearLayoutManager(this)
        binding.rvContacts.adapter = contactsAdapter

        binding.btnContactsCorporate.setOnClickListener { selectContactsSource(false) }
        binding.btnContactsDevice.setOnClickListener { selectContactsSource(true) }

        binding.btnRefreshContacts.setOnClickListener {
            if (isDeviceContactsSelected) {
                loadDeviceContacts()
            } else {
                loadCorporateContacts()
            }
        }

        binding.etContactSearch.doAfterTextChanged { text ->
            filterContacts(text?.toString() ?: "")
        }

        // With READ_CONTACTS granted, preload the device contacts in the background,
        // so phone contacts are found instantly too when searching on the organisation tab (§5.6)
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_CONTACTS) == PackageManager.PERMISSION_GRANTED) {
            loadDeviceContacts()
        }
    }

    private fun filterContacts(query: String) {
        contactsAdapter.filterUnified(query, corporateContactsList, deviceContactsList, isDeviceContactsSelected)
        val count = contactsAdapter.itemCount
        if (count == 0) {
            binding.tvEmptyContacts.text = if (query.trim().isNotEmpty()) {
                getString(R.string.contacts_no_match, query)
            } else if (isDeviceContactsSelected) {
                getString(R.string.contacts_phone_empty)
            } else {
                getString(R.string.contacts_company_empty)
            }
            binding.tvEmptyContacts.visibility = View.VISIBLE
        } else {
            binding.tvEmptyContacts.visibility = View.GONE
        }
    }

    private fun selectContactsSource(useDevice: Boolean) {
        isDeviceContactsSelected = useDevice

        val colorWhite = ContextCompat.getColor(this, R.color.card_bg)
        val colorSecondaryText = ContextCompat.getColor(this, R.color.text_secondary)

        if (useDevice) {
            binding.btnContactsCorporate.backgroundTintList = ContextCompat.getColorStateList(this, R.color.dialpad_btn_bg)
            binding.btnContactsCorporate.setTextColor(colorSecondaryText)
            binding.btnContactsDevice.backgroundTintList = ContextCompat.getColorStateList(this, R.color.primary)
            binding.btnContactsDevice.setTextColor(colorWhite)

            if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_CONTACTS) != PackageManager.PERMISSION_GRANTED) {
                requestPermissionLauncher.launch(arrayOf(Manifest.permission.READ_CONTACTS))
            } else {
                loadDeviceContacts()
            }
        } else {
            binding.btnContactsCorporate.backgroundTintList = ContextCompat.getColorStateList(this, R.color.primary)
            binding.btnContactsCorporate.setTextColor(colorWhite)
            binding.btnContactsDevice.backgroundTintList = ContextCompat.getColorStateList(this, R.color.dialpad_btn_bg)
            binding.btnContactsDevice.setTextColor(colorSecondaryText)

            loadCorporateContacts()
        }
        filterContacts(binding.etContactSearch.text?.toString() ?: "")
    }

    private fun loadCorporateContacts() {
        val token = prefs.token ?: return
        val baseUrl = prefs.serverUrl

        binding.pbContacts.visibility = View.VISIBLE
        binding.tvEmptyContacts.visibility = View.GONE

        lifecycleScope.launch {
            val result = apiClient.getContacts(baseUrl, token)
            binding.pbContacts.visibility = View.GONE

            result.onSuccess { response ->
                corporateContactsList = response.contacts ?: emptyList()
                if (::historyAdapter.isInitialized) updateHistoryChatExtensions()
                filterContacts(binding.etContactSearch.text?.toString() ?: "")
            }.onFailure { err ->
                Toast.makeText(this@DialerActivity, getString(R.string.err_contacts_load, err.message), Toast.LENGTH_SHORT).show()
                filterContacts(binding.etContactSearch.text?.toString() ?: "")
            }
        }
    }

    private fun loadDeviceContacts() {
        binding.pbContacts.visibility = View.VISIBLE
        binding.tvEmptyContacts.visibility = View.GONE

        lifecycleScope.launch {
            val list = withContext(Dispatchers.IO) {
                val contactsList = mutableListOf<ContactItem>()
                val seenNumbers = HashSet<String>()
                val uri = ContactsContract.CommonDataKinds.Phone.CONTENT_URI
                val projection = arrayOf(
                    ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME,
                    ContactsContract.CommonDataKinds.Phone.NUMBER
                )
                try {
                    val cursor = contentResolver.query(
                        uri,
                        projection,
                        null,
                        null,
                        "${ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME} ASC"
                    )

                    cursor?.use { c ->
                        val nameIdx = c.getColumnIndex(ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME)
                        val numIdx = c.getColumnIndex(ContactsContract.CommonDataKinds.Phone.NUMBER)
                        while (c.moveToNext()) {
                            val name = if (nameIdx >= 0) c.getString(nameIdx) ?: getString(R.string.contact_unnamed) else getString(R.string.contact_unnamed)
                            val rawNum = if (numIdx >= 0) c.getString(numIdx) ?: "" else ""
                            val cleanNum = rawNum.replace(Regex("[^0-9+]"), "")
                            if (cleanNum.isNotEmpty() && !seenNumbers.contains(cleanNum)) {
                                seenNumbers.add(cleanNum)
                                contactsList.add(
                                    ContactItem(
                                        extension = cleanNum,
                                        name = name,
                                        role = getString(R.string.contact_role_device),
                                        status = "offline",
                                        sipStatus = null,
                                        webrtcStatus = null
                                    )
                                )
                            }
                        }
                    }
                } catch (e: Exception) {
                    // Fallback on permission error
                }
                contactsList
            }

            binding.pbContacts.visibility = View.GONE
            deviceContactsList = list
            filterContacts(binding.etContactSearch.text?.toString() ?: "")
        }
    }

    // ================= TAB 4: CHAT =================

    private fun confirmDeleteChatMessage(msg: ChatMessage) {
        AlertDialog.Builder(this)
            .setTitle(getString(R.string.chat_delete_message))
            .setMessage(getString(R.string.chat_delete_message_confirm))
            .setPositiveButton(getString(R.string.ui_delete)) { _, _ ->
                val url = prefs.serverUrl
                val token = prefs.token ?: return@setPositiveButton
                lifecycleScope.launch {
                    apiClient.deleteChatMessage(url, token, msg.id)
                        .onSuccess { chatMessageAdapter.markDeleted(msg.id) }
                        .onFailure {
                            Toast.makeText(this@DialerActivity, it.message, Toast.LENGTH_LONG).show()
                        }
                }
            }
            .setNegativeButton(getString(R.string.btn_dismiss), null)
            .show()
    }

    private fun setupChatTab() {
        chatAdapter = ChatConversationAdapter { conv ->
            val isGroup = conv.type == "group"
            val targetExt = if (isGroup) "" else (conv.targetExt ?: "")
            val displayName = if (isGroup) (conv.title ?: getString(R.string.group_chat)) else (conv.targetName ?: conv.targetExt ?: "")
            openChatRoom(conv.id, targetExt, displayName, isGroup)
        }
        binding.rvChatConversations.layoutManager = LinearLayoutManager(this)
        binding.rvChatConversations.adapter = chatAdapter

        // Embedded Chat Room setup
        val myExt = prefs.extension ?: ""
        val baseUrl = prefs.serverUrl ?: ""
        // Named: a trailing lambda would bind to the long-press handler, leaving the
        // token empty (images answered 401) and long press without effect.
        chatMessageAdapter = ChatMessageAdapter(
            myExt, baseUrl, false,
            tokenProvider = { prefs.token },
            onOwnMessageLongPress = { msg -> confirmDeleteChatMessage(msg) }
        )
        val msgLm = LinearLayoutManager(this).apply {
            stackFromEnd = true
        }
        binding.rvChatMessages.layoutManager = msgLm
        binding.rvChatMessages.adapter = chatMessageAdapter

        binding.rvChatMessages.addOnLayoutChangeListener { _, _, _, _, bottom, _, _, _, oldBottom ->
            if (bottom < oldBottom && ::chatMessageAdapter.isInitialized && chatMessageAdapter.itemCount > 0) {
                binding.rvChatMessages.post {
                    binding.rvChatMessages.scrollToPosition(chatMessageAdapter.itemCount - 1)
                }
            }
        }

        binding.etChatMessage.setOnFocusChangeListener { _, hasFocus ->
            if (hasFocus && ::chatMessageAdapter.isInitialized && chatMessageAdapter.itemCount > 0) {
                binding.rvChatMessages.postDelayed({
                    binding.rvChatMessages.scrollToPosition(chatMessageAdapter.itemCount - 1)
                }, 150)
            }
        }

        binding.etChatMessage.addTextChangedListener(object : android.text.TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: android.text.Editable?) {
                sendChatTypingState(!s.isNullOrEmpty())
            }
        })

        binding.btnChatRoomBack.setOnClickListener {
            closeChatRoom()
        }

        binding.btnChatSend.setOnClickListener {
            sendChatMessage()
        }

        binding.btnChatAttach.setOnClickListener {
            pickChatDocumentLauncher.launch("*/*")
        }

        binding.btnChatCamera.setOnClickListener { anchor ->
            showChatPhotoSourceMenu(anchor)
        }

        binding.btnChatCancelUpload.setOnClickListener {
            chatPendingUpload = null
            binding.llChatUploadPreview.visibility = View.GONE
        }

        binding.btnChatRoomCall.setOnClickListener {
            if (currentChatTargetExt.isNotEmpty()) {
                switchTab(Tab.DIALER)
                initiateCall(currentChatTargetExt, currentChatTargetName)
            }
        }

        binding.btnChatRoomGroupInfo.setOnClickListener {
            showChatRoomGroupInfoDialog()
        }

        binding.llChatRoomHeaderInfo.setOnClickListener {
            if (currentChatIsGroup) {
                showChatRoomGroupInfoDialog()
            }
        }

        binding.btnChatCreate.setOnClickListener { anchor ->
            val popup = androidx.appcompat.widget.PopupMenu(this, anchor)
            popup.menu.add(0, 1, 0, getString(R.string.menu_direct_chat))
            popup.menu.add(0, 2, 1, getString(R.string.menu_new_group))
            popup.setOnMenuItemClickListener { item ->
                when (item.itemId) {
                    1 -> { showNewChatDialog(); true }
                    2 -> { showNewGroupDialog(); true }
                    else -> false
                }
            }
            popup.show()
        }

        binding.btnChatEmptyNewChat.setOnClickListener {
            showNewChatDialog()
        }

        binding.btnChatEmptyNewGroup.setOnClickListener {
            showNewGroupDialog()
        }

        binding.btnChatFilterAll.setOnClickListener {
            setChatFilter(ChatFilter.ALL)
        }

        binding.btnChatFilterDirect.setOnClickListener {
            setChatFilter(ChatFilter.DIRECT)
        }

        binding.btnChatFilterGroups.setOnClickListener {
            setChatFilter(ChatFilter.GROUP)
        }

        binding.etChatSearch.doAfterTextChanged { text ->
            filterConversations(text?.toString() ?: "")
        }
    }

    private fun setChatFilter(filter: ChatFilter) {
        chatFilterMode = filter
        val colorActiveBg = ColorStateList.valueOf(ContextCompat.getColor(this, R.color.primary))
        val colorInactiveBg = ColorStateList.valueOf(ContextCompat.getColor(this, R.color.card_bg))
        val colorActiveText = ContextCompat.getColor(this, android.R.color.white)
        val colorInactiveText = ContextCompat.getColor(this, R.color.text_secondary)

        binding.btnChatFilterAll.backgroundTintList = if (filter == ChatFilter.ALL) colorActiveBg else colorInactiveBg
        binding.btnChatFilterAll.setTextColor(if (filter == ChatFilter.ALL) colorActiveText else colorInactiveText)

        binding.btnChatFilterDirect.backgroundTintList = if (filter == ChatFilter.DIRECT) colorActiveBg else colorInactiveBg
        binding.btnChatFilterDirect.setTextColor(if (filter == ChatFilter.DIRECT) colorActiveText else colorInactiveText)

        binding.btnChatFilterGroups.backgroundTintList = if (filter == ChatFilter.GROUP) colorActiveBg else colorInactiveBg
        binding.btnChatFilterGroups.setTextColor(if (filter == ChatFilter.GROUP) colorActiveText else colorInactiveText)

        filterConversations(binding.etChatSearch.text?.toString() ?: "")
    }

    private fun loadConversations() {
        val sUrl = prefs.serverUrl
        if (sUrl.isEmpty()) return
        val token = prefs.token ?: return

        binding.pbChat.visibility = View.VISIBLE

        lifecycleScope.launch {
            val convRes = apiClient.getChatConversations(sUrl, token)
            binding.pbChat.visibility = View.GONE

            convRes.onSuccess { list ->
                allConversations.clear()
                allConversations.addAll(list)
                filterConversations(binding.etChatSearch.text?.toString() ?: "")
                updateChatUnreadBadge()
            }

            val contactsRes = apiClient.getContacts(sUrl, token)
            contactsRes.onSuccess { response ->
                chatCorporateContacts.clear()
                chatCorporateContacts.addAll(response.contacts ?: emptyList())
                filterConversations(binding.etChatSearch.text?.toString() ?: "")
            }
        }
    }

    private fun filterConversations(query: String) {
        val q = query.trim()
        val myExt = prefs.extension ?: ""
        val displayList = mutableListOf<ChatConversation>()

        val baseList = when (chatFilterMode) {
            ChatFilter.ALL -> allConversations
            ChatFilter.DIRECT -> allConversations.filter { it.type != "group" }
            ChatFilter.GROUP -> allConversations.filter { it.type == "group" }
        }

        if (q.isEmpty()) {
            displayList.addAll(baseList)
        } else {
            val matchedConversations = baseList.filter {
                (it.type == "group" && SearchUtils.matches(it.title, q)) ||
                SearchUtils.matches(it.targetName, q) ||
                SearchUtils.matches(it.targetExt, q) ||
                SearchUtils.matches(it.lastMessageText, q)
            }
            displayList.addAll(matchedConversations)

            if (chatFilterMode != ChatFilter.GROUP) {
                val matchedContacts = chatCorporateContacts.filter { contact ->
                    contact.extension != myExt && (
                        SearchUtils.matches(contact.name, q) ||
                        SearchUtils.matches(contact.extension, q) ||
                        SearchUtils.matches(contact.role, q)
                    )
                }

                for (contact in matchedContacts) {
                    val alreadyInList = matchedConversations.any { it.targetExt == contact.extension }
                    if (!alreadyInList) {
                        val isOnline = contact.status.equals("online", true) ||
                                contact.sipStatus.equals("online", true) ||
                                contact.webrtcStatus.equals("online", true)
                        displayList.add(
                            ChatConversation(
                                id = 0,
                                type = "direct",
                                directKey = null,
                                title = null,
                                createdBy = "",
                                lastMessageText = getString(R.string.chat_start_with_contact, contact.extension),
                                lastMessageAt = null,
                                unreadCount = 0,
                                targetExt = contact.extension,
                                targetName = contact.name,
                                targetOnline = isOnline
                            )
                        )
                    }
                }
            }
        }

        chatAdapter.submitList(displayList)
        if (displayList.isEmpty()) {
            binding.llChatEmptyState.visibility = View.VISIBLE
            binding.rvChatConversations.visibility = View.GONE

            if (chatFilterMode == ChatFilter.GROUP) {
                binding.tvChatEmptyTitle.text = getString(R.string.chat_no_groups)
                binding.tvChatEmptySubtitle.text = getString(R.string.chat_no_groups_desc)
                binding.btnChatEmptyNewChat.visibility = View.GONE
                binding.btnChatEmptyNewGroup.visibility = View.VISIBLE
            } else {
                binding.tvChatEmptyTitle.text = getString(R.string.ui_no_chats_yet)
                binding.tvChatEmptySubtitle.text = getString(R.string.ui_no_chats_desc)
                binding.btnChatEmptyNewChat.visibility = View.VISIBLE
                binding.btnChatEmptyNewGroup.visibility = View.VISIBLE
            }
        } else {
            binding.llChatEmptyState.visibility = View.GONE
            binding.rvChatConversations.visibility = View.VISIBLE
        }
    }

    private fun showNewGroupDialog() {
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl
            if (sUrl.isEmpty()) return@launch
            val token = prefs.token ?: return@launch

            val contacts = if (chatCorporateContacts.isNotEmpty()) {
                chatCorporateContacts
            } else {
                val res = apiClient.getContacts(sUrl, token)
                val fetched = res.getOrNull()?.contacts ?: emptyList()
                chatCorporateContacts.clear()
                chatCorporateContacts.addAll(fetched)
                fetched
            }

            val myExt = prefs.extension ?: ""
            val otherContacts = contacts.filter { it.extension != myExt }

            if (otherContacts.isEmpty()) {
                AlertDialog.Builder(this@DialerActivity)
                    .setTitle(getString(R.string.menu_new_group))
                    .setMessage(getString(R.string.group_no_more_extensions))
                    .setPositiveButton(getString(R.string.btn_ok), null)
                    .show()
                return@launch
            }

            val dialogBinding = DialogNewGroupBinding.inflate(layoutInflater)
            val dialog = AlertDialog.Builder(this@DialerActivity)
                .setView(dialogBinding.root)
                .create()

            val selectionAdapter = ContactSelectionAdapter { selected ->
                dialogBinding.tvSelectedCount.text = getString(R.string.select_members_count, selected.size)
            }

            dialogBinding.rvGroupMembers.layoutManager = LinearLayoutManager(this@DialerActivity)
            dialogBinding.rvGroupMembers.adapter = selectionAdapter
            selectionAdapter.submitList(otherContacts)

            dialogBinding.etSearchMember.doAfterTextChanged { s ->
                selectionAdapter.filter(s?.toString() ?: "")
            }

            dialogBinding.btnCancelNewGroup.setOnClickListener {
                dialog.dismiss()
            }

            dialogBinding.btnSubmitNewGroup.setOnClickListener {
                val title = dialogBinding.etGroupTitle.text?.toString()?.trim() ?: ""
                val desc = dialogBinding.etGroupDesc.text?.toString()?.trim()
                val selectedMembers = selectionAdapter.getSelectedExtensions().toList()

                if (title.isEmpty()) {
                    Toast.makeText(this@DialerActivity, getString(R.string.err_group_name_required), Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }

                if (selectedMembers.isEmpty()) {
                    Toast.makeText(this@DialerActivity, getString(R.string.err_select_one_member), Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }

                dialogBinding.btnSubmitNewGroup.isEnabled = false
                dialogBinding.btnSubmitNewGroup.text = getString(R.string.msg_creating)

                lifecycleScope.launch {
                    val createRes = apiClient.createGroupChat(
                        baseUrl = sUrl,
                        token = token,
                        title = title,
                        description = if (desc.isNullOrEmpty()) null else desc,
                        avatarUrl = null,
                        members = selectedMembers
                    )
                    createRes.onSuccess { newConv ->
                        dialog.dismiss()
                        loadConversations()
                        openChatRoom(
                            convId = newConv.id,
                            targetExt = "",
                            targetName = newConv.title,
                            isGroup = true
                        )
                    }.onFailure { err ->
                        dialogBinding.btnSubmitNewGroup.isEnabled = true
                        dialogBinding.btnSubmitNewGroup.text = getString(R.string.ui_create_group)
                        Toast.makeText(this@DialerActivity, getString(R.string.err_generic, err.message), Toast.LENGTH_LONG).show()
                    }
                }
            }

            dialog.show()
        }
    }

    private fun showNewChatDialog() {
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl
            if (sUrl.isEmpty()) return@launch
            val token = prefs.token ?: return@launch

            val contacts = if (chatCorporateContacts.isNotEmpty()) {
                chatCorporateContacts
            } else {
                val res = apiClient.getContacts(sUrl, token)
                val fetched = res.getOrNull()?.contacts ?: emptyList()
                chatCorporateContacts.clear()
                chatCorporateContacts.addAll(fetched)
                fetched
            }

            val myExt = prefs.extension ?: ""
            val otherContacts = contacts.filter { it.extension != myExt }

            if (otherContacts.isEmpty()) {
                AlertDialog.Builder(this@DialerActivity)
                    .setTitle(getString(R.string.internal_directory_title))
                    .setMessage(getString(R.string.no_extensions_to_chat))
                    .setPositiveButton(getString(R.string.btn_ok), null)
                    .show()
                return@launch
            }

            val dialogBinding = com.mhrgl.aipbx.databinding.DialogNewChatBinding.inflate(layoutInflater)
            val dialog = AlertDialog.Builder(this@DialerActivity)
                .setView(dialogBinding.root)
                .create()

            val pickerAdapter = ChatContactPickerAdapter { selected ->
                dialog.dismiss()
                openChatRoom(0, selected.extension, selected.name, false)
            }

            dialogBinding.rvNewChatContacts.layoutManager = LinearLayoutManager(this@DialerActivity)
            dialogBinding.rvNewChatContacts.adapter = pickerAdapter
            pickerAdapter.submitList(otherContacts)

            dialogBinding.etSearchContact.doAfterTextChanged { s ->
                val q = s?.toString() ?: ""
                pickerAdapter.filter(q)
                if (pickerAdapter.itemCount == 0) {
                    dialogBinding.tvEmptyNewChat.visibility = View.VISIBLE
                    dialogBinding.rvNewChatContacts.visibility = View.GONE
                } else {
                    dialogBinding.tvEmptyNewChat.visibility = View.GONE
                    dialogBinding.rvNewChatContacts.visibility = View.VISIBLE
                }
            }

            dialogBinding.btnCancelNewChat.setOnClickListener {
                dialog.dismiss()
            }

            dialog.show()
        }
    }

    // --- Embedded Chat Room Functionality ---

    fun isChatRoomOpen(): Boolean = binding.layoutChatRoom.visibility == View.VISIBLE

    private fun openChatRoom(convId: Int, targetExt: String = "", targetName: String? = null, isGroup: Boolean = false) {
        if (chatTypingSent && chatTypingConvId > 0 && chatTypingConvId != convId) {
            sendChatTypingState(false)
        }
        currentChatConvId = convId
        currentChatTargetExt = targetExt
        currentChatTargetName = targetName ?: targetExt
        currentChatIsGroup = isGroup
        currentChatGroupDetails = null
        ChatKeys.activeConversationId = convId

        if (convId > 0) {
            val nm = getSystemService(Context.NOTIFICATION_SERVICE) as? android.app.NotificationManager
            nm?.cancel(10000 + (convId % 1000))
        }

        binding.layoutChatList.visibility = View.GONE
        binding.layoutChatRoom.visibility = View.VISIBLE
        binding.bottomNav.visibility = View.GONE

        chatMessageAdapter.setIsGroup(isGroup)
        chatMessageAdapter.submitList(emptyList())
        binding.etChatMessage.setText("")
        chatPendingUpload = null
        binding.llChatUploadPreview.visibility = View.GONE
        binding.tvChatTyping.visibility = View.GONE

        updateChatRoomHeader()
        ensureChatConversationAndLoadMessages()
    }

    private fun closeChatRoom() {
        sendChatTypingState(false)
        binding.layoutChatRoom.visibility = View.GONE
        binding.layoutChatList.visibility = View.VISIBLE
        binding.bottomNav.visibility = View.VISIBLE
        currentChatConvId = 0
        currentChatTargetExt = ""
        currentChatTargetName = ""
        currentChatIsGroup = false
        currentChatGroupDetails = null
        ChatKeys.activeConversationId = 0

        loadConversations()
        updateChatUnreadBadge()
    }

    private fun updateChatRoomHeader() {
        if (currentChatIsGroup) {
            binding.btnChatRoomCall.visibility = View.GONE
            binding.btnChatRoomGroupInfo.visibility = View.VISIBLE
            binding.vChatRoomOnlineDot.visibility = View.GONE
            binding.tvChatRoomAvatar.text = "👥"
            binding.tvChatRoomAvatar.backgroundTintList = ColorStateList.valueOf(0xFF4F46E5.toInt())
            binding.tvChatRoomTargetName.text = if (currentChatTargetName.isNotEmpty()) currentChatTargetName else getString(R.string.group_chat)
            val grp = currentChatGroupDetails
            binding.tvChatRoomTargetStatus.text = if (grp != null) {
                getString(R.string.group_members_online_grp, grp.memberCount, grp.onlineCount)
            } else {
                getString(R.string.group_chat)
            }
        } else {
            binding.btnChatRoomCall.visibility = View.VISIBLE
            binding.btnChatRoomGroupInfo.visibility = View.GONE
            binding.vChatRoomOnlineDot.visibility = View.VISIBLE
            binding.tvChatRoomAvatar.text = currentChatTargetName.take(1).uppercase()
            binding.tvChatRoomAvatar.backgroundTintList = null
            binding.tvChatRoomTargetName.text = if (currentChatTargetName.isNotEmpty()) {
                "$currentChatTargetName (#$currentChatTargetExt)"
            } else {
                getString(R.string.chat_extension_title, currentChatTargetExt)
            }
            // The current state the socket knows instead of a fixed "Offline" — the presence
            // event does not come again while the other side is already connected.
            applyChatRoomPresence(ChatWebSocketManager.instance.isOnline(currentChatTargetExt))
        }
    }

    private fun applyChatRoomPresence(isOnline: Boolean) {
        binding.tvChatRoomTargetStatus.text = if (isOnline) getString(R.string.status_online) else getString(R.string.ui_offline)
        binding.tvChatRoomTargetStatus.setTextColor(if (isOnline) 0xFF10B981.toInt() else 0xFF64748B.toInt())
        binding.vChatRoomOnlineDot.backgroundTintList = ColorStateList.valueOf(
            if (isOnline) 0xFF10B981.toInt() else 0xFF9CA3AF.toInt()
        )
    }

    private fun ensureChatConversationAndLoadMessages() {
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            if (!currentChatIsGroup && currentChatConvId <= 0 && currentChatTargetExt.isNotEmpty()) {
                val requestedExt = currentChatTargetExt
                val createRes = apiClient.createDirectChat(sUrl, token, requestedExt)
                createRes.onSuccess { conv ->
                    // The room was closed (or another one opened) while the request ran:
                    // marking this conversation "on screen" would silence its notifications.
                    if (!isChatRoomOpen() || currentChatTargetExt != requestedExt) return@onSuccess
                    currentChatConvId = conv.id
                    ChatKeys.activeConversationId = conv.id
                    val nm = getSystemService(Context.NOTIFICATION_SERVICE) as? android.app.NotificationManager
                    nm?.cancel(10000 + (conv.id % 1000))
                    loadChatRoomMessages()
                }.onFailure {
                    Toast.makeText(this@DialerActivity, getString(R.string.err_chat_create, it.message), Toast.LENGTH_SHORT).show()
                }
            } else if (currentChatConvId > 0) {
                loadChatRoomMessages()
                if (currentChatIsGroup || currentChatTargetExt.isEmpty()) {
                    loadChatRoomGroupDetails()
                }
            }
        }
    }

    private fun loadChatRoomMessages() {
        if (currentChatConvId <= 0) return
        val convId = currentChatConvId
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            val res = apiClient.getChatMessages(sUrl, token, convId, 50)
            res.onSuccess { list ->
                if (currentChatConvId == convId && isChatRoomOpen()) {
                    chatMessageAdapter.submitList(list)
                    binding.rvChatMessages.scrollToPosition((list.size - 1).coerceAtLeast(0))
                    ChatWebSocketManager.instance.sendMarkRead(convId, list.lastOrNull()?.id ?: 0L)
                }
            }
        }
    }

    private fun loadChatRoomGroupDetails() {
        if (currentChatConvId <= 0) return
        val convId = currentChatConvId
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            val res = apiClient.getGroupDetails(sUrl, token, convId)
            res.onSuccess { conv ->
                if (currentChatConvId == convId && isChatRoomOpen()) {
                    currentChatGroupDetails = conv
                    currentChatIsGroup = true
                    runOnUiThread {
                        chatMessageAdapter.setIsGroup(true)
                        updateChatRoomHeader()
                    }
                }
            }
        }
    }

    private fun sendChatMessage() {
        sendChatTypingState(false)
        val text = binding.etChatMessage.text.toString().trim()
        val upload = chatPendingUpload
        if (text.isEmpty() && upload == null) return
        if (currentChatConvId <= 0) return

        val msgType = upload?.msgType ?: "text"
        val attachUrl = upload?.attachmentUrl
        val fileName = upload?.fileName
        val fileSize = upload?.fileSize ?: 0L
        val mimeType = upload?.mimeType

        ChatWebSocketManager.instance.sendMessage(
            convId = currentChatConvId,
            msgType = msgType,
            message = text,
            attachmentUrl = attachUrl,
            fileName = fileName,
            fileSize = fileSize,
            mimeType = mimeType
        )

        binding.etChatMessage.setText("")
        chatPendingUpload = null
        binding.llChatUploadPreview.visibility = View.GONE
    }

    private val chatTypingHandler = android.os.Handler(android.os.Looper.getMainLooper())
    private var chatTypingSent = false
    private var chatTypingSentAt = 0L
    private var chatTypingConvId = 0
    private val stopChatTyping = Runnable { sendChatTypingState(false) }

    private fun sendChatTypingState(typing: Boolean) {
        val targetConvId = if (currentChatConvId > 0) currentChatConvId else chatTypingConvId
        if (targetConvId <= 0) return
        chatTypingHandler.removeCallbacks(stopChatTyping)
        if (typing) {
            val now = android.os.SystemClock.elapsedRealtime()
            if (!chatTypingSent || chatTypingConvId != targetConvId || now - chatTypingSentAt > 3000) {
                ChatWebSocketManager.instance.sendTyping(targetConvId, true)
                chatTypingSent = true
                chatTypingConvId = targetConvId
                chatTypingSentAt = now
            }
            chatTypingHandler.postDelayed(stopChatTyping, 4000)
        } else if (chatTypingSent) {
            ChatWebSocketManager.instance.sendTyping(targetConvId, false)
            chatTypingSent = false
            chatTypingConvId = 0
        }
    }

    /** Picture preview in the upload bar (null: the clip icon for other files). */
    private fun showChatUploadThumb(bitmap: android.graphics.Bitmap?) {
        binding.ivChatUploadThumb.setImageBitmap(bitmap)
        binding.ivChatUploadThumb.visibility = if (bitmap != null) View.VISIBLE else View.GONE
        binding.ivChatUploadIcon.visibility = if (bitmap != null) View.GONE else View.VISIBLE
    }

    private fun handleChatPickedUri(uri: Uri, type: String) {
        lifecycleScope.launch {
            binding.llChatUploadPreview.visibility = View.VISIBLE
            binding.tvChatUploadFilename.text = getString(R.string.msg_uploading_file)
            showChatUploadThumb(withContext(Dispatchers.IO) { ChatUploadPrep.thumbnail(this@DialerActivity, uri) })

            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            val prepared = withContext(Dispatchers.IO) {
                ChatUploadPrep.prepare(this@DialerActivity, uri, type)
            }

            if (prepared == null) {
                Toast.makeText(this@DialerActivity, getString(R.string.err_file_read), Toast.LENGTH_SHORT).show()
                binding.llChatUploadPreview.visibility = View.GONE
                return@launch
            }

            val uploadRes = apiClient.uploadChatFile(sUrl, token, prepared.file, prepared.mimeType)
            prepared.cleanup()
            uploadRes.onSuccess { res ->
                chatPendingUpload = res
                binding.tvChatUploadFilename.text = res.fileName ?: prepared.file.name
            }.onFailure {
                Toast.makeText(this@DialerActivity, getString(R.string.err_upload, it.message), Toast.LENGTH_LONG).show()
                binding.llChatUploadPreview.visibility = View.GONE
            }
        }
    }

    private fun showChatRoomGroupInfoDialog() {
        if (!currentChatIsGroup || currentChatConvId <= 0) return
        val convId = currentChatConvId

        lifecycleScope.launch {
            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            val res = apiClient.getGroupDetails(sUrl, token, convId)
            val group = res.getOrNull() ?: currentChatGroupDetails
            if (group == null) {
                Toast.makeText(this@DialerActivity, getString(R.string.err_group_details), Toast.LENGTH_SHORT).show()
                return@launch
            }
            currentChatGroupDetails = group

            val dialogBinding = DialogGroupInfoBinding.inflate(layoutInflater)
            val dialog = AlertDialog.Builder(this@DialerActivity)
                .setView(dialogBinding.root)
                .create()

            val myExt = prefs.extension ?: ""
            val isAdmin = group.myRole.equals("admin", ignoreCase = true)

            dialogBinding.tvGroupInfoAvatar.text = "👥"
            dialogBinding.tvGroupInfoAvatar.backgroundTintList = ColorStateList.valueOf(0xFF4F46E5.toInt())
            dialogBinding.tvGroupInfoTitle.text = group.title ?: getString(R.string.group_chat)
            dialogBinding.tvGroupInfoSubtitle.text = getString(R.string.group_members_online_info, group.memberCount, group.onlineCount)
            if (!group.description.isNullOrEmpty()) {
                dialogBinding.tvGroupInfoDesc.visibility = View.VISIBLE
                dialogBinding.tvGroupInfoDesc.text = group.description
            } else {
                dialogBinding.tvGroupInfoDesc.visibility = View.GONE
            }

            if (isAdmin) {
                dialogBinding.llAdminActions.visibility = View.VISIBLE
                dialogBinding.btnDeleteGroup.visibility = View.VISIBLE
            } else {
                dialogBinding.llAdminActions.visibility = View.GONE
                dialogBinding.btnDeleteGroup.visibility = View.GONE
            }

            val participantAdapter = GroupParticipantAdapter(
                myExtension = myExt,
                isAdmin = isAdmin,
                onToggleAdminRole = { participant ->
                    val newRole = if (participant.role == "admin") "member" else "admin"
                    lifecycleScope.launch {
                        val roleRes = apiClient.updateGroupMemberRole(sUrl, token, convId, participant.extension, newRole)
                        roleRes.onSuccess {
                            dialog.dismiss()
                            showChatRoomGroupInfoDialog()
                            loadChatRoomGroupDetails()
                        }.onFailure {
                            Toast.makeText(this@DialerActivity, getString(R.string.err_role_change, it.message), Toast.LENGTH_SHORT).show()
                        }
                    }
                },
                onRemoveMember = { participant ->
                    AlertDialog.Builder(this@DialerActivity)
                        .setTitle(getString(R.string.remove_member_title))
                        .setMessage(getString(R.string.remove_member_confirm, participant.name ?: participant.extension))
                        .setPositiveButton(getString(R.string.btn_remove)) { _, _ ->
                            lifecycleScope.launch {
                                val remRes = apiClient.removeGroupMember(sUrl, token, convId, participant.extension)
                                remRes.onSuccess {
                                    dialog.dismiss()
                                    showChatRoomGroupInfoDialog()
                                    loadChatRoomGroupDetails()
                                }.onFailure {
                                    Toast.makeText(this@DialerActivity, getString(R.string.err_member_remove, it.message), Toast.LENGTH_SHORT).show()
                                }
                            }
                        }
                        .setNegativeButton(getString(R.string.btn_cancel), null)
                        .show()
                }
            )

            dialogBinding.rvGroupParticipants.layoutManager = LinearLayoutManager(this@DialerActivity)
            dialogBinding.rvGroupParticipants.adapter = participantAdapter
            participantAdapter.submitList(group.participants ?: emptyList())

            dialogBinding.btnEditGroupInfo.setOnClickListener {
                showEditChatGroupInfoDialog(group) {
                    dialog.dismiss()
                    showChatRoomGroupInfoDialog()
                    loadChatRoomGroupDetails()
                }
            }

            dialogBinding.btnAddMember.setOnClickListener {
                showAddChatMembersDialog(group) {
                    dialog.dismiss()
                    showChatRoomGroupInfoDialog()
                    loadChatRoomGroupDetails()
                }
            }

            dialogBinding.btnLeaveGroup.setOnClickListener {
                AlertDialog.Builder(this@DialerActivity)
                    .setTitle(getString(R.string.ui_leave_group))
                    .setMessage(getString(R.string.leave_group_confirm))
                    .setPositiveButton(getString(R.string.btn_leave)) { _, _ ->
                        lifecycleScope.launch {
                            val leaveRes = apiClient.leaveGroup(sUrl, token, convId)
                            leaveRes.onSuccess {
                                Toast.makeText(this@DialerActivity, getString(R.string.msg_left_group), Toast.LENGTH_SHORT).show()
                                dialog.dismiss()
                                closeChatRoom()
                            }.onFailure {
                                Toast.makeText(this@DialerActivity, getString(R.string.err_action_failed, it.message), Toast.LENGTH_LONG).show()
                            }
                        }
                    }
                    .setNegativeButton(getString(R.string.btn_dismiss), null)
                    .show()
            }

            dialogBinding.btnDeleteGroup.setOnClickListener {
                AlertDialog.Builder(this@DialerActivity)
                    .setTitle(getString(R.string.ui_delete_group))
                    .setMessage(getString(R.string.delete_group_confirm))
                    .setPositiveButton(getString(R.string.ui_delete)) { _, _ ->
                        lifecycleScope.launch {
                            val delRes = apiClient.deleteGroup(sUrl, token, convId)
                            delRes.onSuccess {
                                Toast.makeText(this@DialerActivity, getString(R.string.msg_group_deleted), Toast.LENGTH_SHORT).show()
                                dialog.dismiss()
                                closeChatRoom()
                            }.onFailure {
                                Toast.makeText(this@DialerActivity, getString(R.string.err_delete, it.message), Toast.LENGTH_LONG).show()
                            }
                        }
                    }
                    .setNegativeButton(getString(R.string.btn_dismiss), null)
                    .show()
            }

            dialogBinding.btnCloseGroupInfo.setOnClickListener {
                dialog.dismiss()
            }

            dialog.show()
        }
    }

    private fun showEditChatGroupInfoDialog(group: ChatConversation, onUpdated: () -> Unit) {
        val view = LayoutInflater.from(this).inflate(R.layout.dialog_new_group, null)
        val etTitle = view.findViewById<EditText>(R.id.etGroupTitle)
        val etDesc = view.findViewById<EditText>(R.id.etGroupDesc)
        val tvSelected = view.findViewById<TextView>(R.id.tvSelectedCount)
        val etSearch = view.findViewById<EditText>(R.id.etSearchMember)
        val rvMembers = view.findViewById<RecyclerView>(R.id.rvGroupMembers)
        val btnSubmit = view.findViewById<Button>(R.id.btnSubmitNewGroup)
        val btnCancel = view.findViewById<Button>(R.id.btnCancelNewGroup)

        tvSelected.visibility = View.GONE
        etSearch.visibility = View.GONE
        rvMembers.visibility = View.GONE
        btnSubmit.text = getString(R.string.features_btn_save)

        etTitle.setText(group.title ?: "")
        etDesc.setText(group.description ?: "")

        val dialog = AlertDialog.Builder(this)
            .setTitle(getString(R.string.edit_group_title))
            .setView(view)
            .create()

        btnCancel.setOnClickListener { dialog.dismiss() }

        btnSubmit.setOnClickListener {
            val title = etTitle.text.toString().trim()
            val desc = etDesc.text.toString().trim()

            if (title.isEmpty()) {
                Toast.makeText(this, getString(R.string.err_group_title_empty), Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            btnSubmit.isEnabled = false
            lifecycleScope.launch {
                val sUrl = prefs.serverUrl ?: return@launch
                val token = prefs.token ?: return@launch

                val updateRes = apiClient.updateGroupInfo(
                    baseUrl = sUrl,
                    token = token,
                    convId = group.id,
                    title = title,
                    avatarUrl = null,
                    description = desc
                )
                updateRes.onSuccess {
                    dialog.dismiss()
                    onUpdated()
                }.onFailure { err ->
                    btnSubmit.isEnabled = true
                    Toast.makeText(this@DialerActivity, getString(R.string.err_update_err, err.message), Toast.LENGTH_SHORT).show()
                }
            }
        }

        dialog.show()
    }

    private fun showAddChatMembersDialog(group: ChatConversation, onAdded: () -> Unit) {
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            val contactsRes = apiClient.getContacts(sUrl, token)
            val allContacts = contactsRes.getOrNull()?.contacts ?: emptyList()

            val existingExts = group.participants?.map { it.extension }?.toSet() ?: emptySet()
            val availableContacts = allContacts.filter { !existingExts.contains(it.extension) }

            if (availableContacts.isEmpty()) {
                Toast.makeText(this@DialerActivity, getString(R.string.no_new_extensions), Toast.LENGTH_SHORT).show()
                return@launch
            }

            val view = LayoutInflater.from(this@DialerActivity).inflate(R.layout.dialog_new_group, null)
            val etTitle = view.findViewById<EditText>(R.id.etGroupTitle)
            val etDesc = view.findViewById<EditText>(R.id.etGroupDesc)
            val tvSelected = view.findViewById<TextView>(R.id.tvSelectedCount)
            val etSearch = view.findViewById<EditText>(R.id.etSearchMember)
            val rvMembers = view.findViewById<RecyclerView>(R.id.rvGroupMembers)
            val btnSubmit = view.findViewById<Button>(R.id.btnSubmitNewGroup)
            val btnCancel = view.findViewById<Button>(R.id.btnCancelNewGroup)

            etTitle.visibility = View.GONE
            etDesc.visibility = View.GONE
            btnSubmit.text = getString(R.string.btn_add_members)

            val selectionAdapter = ContactSelectionAdapter { selected ->
                tvSelected.text = getString(R.string.select_members_count, selected.size)
            }
            rvMembers.layoutManager = LinearLayoutManager(this@DialerActivity)
            rvMembers.adapter = selectionAdapter
            selectionAdapter.submitList(availableContacts)

            etSearch.addTextChangedListener(object : android.text.TextWatcher {
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {
                    selectionAdapter.filter(s?.toString() ?: "")
                }
                override fun afterTextChanged(s: android.text.Editable?) {}
            })

            val dialog = AlertDialog.Builder(this@DialerActivity)
                .setTitle(getString(R.string.add_members_title))
                .setView(view)
                .create()

            btnCancel.setOnClickListener { dialog.dismiss() }

            btnSubmit.setOnClickListener {
                val selected = selectionAdapter.getSelectedExtensions().toList()
                if (selected.isEmpty()) {
                    Toast.makeText(this@DialerActivity, getString(R.string.err_select_member), Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }

                btnSubmit.isEnabled = false
                lifecycleScope.launch {
                    val addRes = apiClient.addGroupMembers(sUrl, token, group.id, selected)
                    addRes.onSuccess {
                        dialog.dismiss()
                        onAdded()
                    }.onFailure {
                        btnSubmit.isEnabled = true
                        Toast.makeText(this@DialerActivity, getString(R.string.err_members_add, it.message), Toast.LENGTH_SHORT).show()
                    }
                }
            }

            dialog.show()
        }
    }

    // ================= TAB 5: FEATURES (DND & CF) =================

    private fun setupFeaturesTab() {
        // 1. Version and system info
        binding.tvAppVersion.text = getString(R.string.app_version_info, BuildConfig.VERSION_NAME, BuildConfig.VERSION_CODE)
        binding.tvDeviceInfo.text = getString(R.string.device_info, Build.MANUFACTURER, Build.MODEL, Build.VERSION.RELEASE, Build.VERSION.SDK_INT)
        val serverUrl = prefs.serverUrl
        binding.tvServerVersion.text = getString(R.string.pbx_info_url, serverUrl)

        // FCM Durumu
        updateFcmStatusUI()

        // Fetch the current version/brand info from the server with ping and show it
        if (serverUrl.isNotEmpty()) {
            lifecycleScope.launch {
                val pingRes = apiClient.ping(serverUrl)
                pingRes.onSuccess { info ->
                    val brand = info.brandTitle ?: "AI PBX"
                    val ver = info.version ?: "1.0"
                    binding.tvServerVersion.text = getString(R.string.pbx_info_full, brand, ver, serverUrl)
                }
            }
        }

        // 2. Duration dropdown
        val timeoutAdapter = ArrayAdapter(this, android.R.layout.simple_dropdown_item_1line, timeoutOptions.map { getString(R.string.seconds_short, it) })
        binding.actvNoAnswerTimeout.setAdapter(timeoutAdapter)
        binding.actvNoAnswerTimeout.setOnItemClickListener { _, _, position, _ ->
            currentNoAnswerTimeout = timeoutOptions.getOrElse(position) { 20 }
        }

        // 3. DND Switch
        binding.switchDnd.setOnCheckedChangeListener { _, isChecked ->
            if (isChecked != isDndEnabled) {
                updateFeaturesOnServer(dnd = isChecked)
            }
        }

        // 3.1 Keep '+' Prefix Switch
        binding.switchSendPlusPrefix.isChecked = prefs.sendPlusPrefix
        binding.switchSendPlusPrefix.setOnCheckedChangeListener { _, isChecked ->
            prefs.sendPlusPrefix = isChecked
        }

        // 4. Save the forwarding settings
        binding.btnSaveForward.setOnClickListener {
            val fwdAlways = binding.etForwardAlways.text?.toString()?.trim() ?: ""
            val fwdBusy = binding.etForwardBusy.text?.toString()?.trim() ?: ""
            val fwdNoAns = binding.etForwardNoAnswer.text?.toString()?.trim() ?: ""

            val timeoutStr = binding.actvNoAnswerTimeout.text?.toString()?.replace(Regex("[^0-9]"), "") ?: ""
            val timeout = timeoutStr.toIntOrNull() ?: currentNoAnswerTimeout

            updateFeaturesOnServer(
                dnd = isDndEnabled,
                forwardAlways = fwdAlways,
                forwardBusy = fwdBusy,
                forwardNoAnswer = fwdNoAns,
                noAnswerTimeout = timeout
            )
        }

        // 5. Clear the forwarding settings
        binding.btnClearForward.setOnClickListener {
            binding.etForwardAlways.setText("")
            binding.etForwardBusy.setText("")
            binding.etForwardNoAnswer.setText("")
            binding.actvNoAnswerTimeout.setText(getString(R.string.seconds_short, 20), false)
            currentNoAnswerTimeout = 20

            updateFeaturesOnServer(
                dnd = isDndEnabled,
                forwardAlways = "",
                forwardBusy = "",
                forwardNoAnswer = "",
                noAnswerTimeout = 20
            )
        }

        binding.btnSyncDevice.setOnClickListener {
            syncDeviceToken()
        }

        binding.btnLanguage.text = getString(R.string.language_current, L10n.displayName())
        binding.btnLanguage.setOnClickListener { L10n.showPicker(this) }
        binding.btnBatteryOptimization.setOnClickListener {
            if (SamsungPowerManagerHelper.isSamsungDevice) {
                AlertDialog.Builder(this)
                    .setTitle(getString(R.string.samsung_guide_title))
                    .setMessage(getString(R.string.samsung_guide_message))
                    .setPositiveButton(getString(R.string.btn_open_settings)) { _, _ ->
                        SamsungPowerManagerHelper.openBatterySettings(this)
                    }
                    .setNegativeButton(getString(R.string.ui_close), null)
                    .show()
            } else {
                AlertDialog.Builder(this)
                    .setTitle(getString(R.string.battery_opt_title))
                    .setMessage(getString(R.string.battery_opt_msg))
                    .setPositiveButton(getString(R.string.btn_open_settings)) { _, _ ->
                        SamsungPowerManagerHelper.openBatterySettings(this)
                    }
                    .setNegativeButton(getString(R.string.ui_close), null)
                    .show()
            }
        }

        binding.btnFullScreenCalls.setOnClickListener {
            if (FullScreenCallPrompt.isAllowed(this)) {
                FullScreenCallPrompt.openSettings(this)
            } else {
                FullScreenCallPrompt.showExplanation(this)
            }
        }

        binding.btnPrivacyPolicy.setOnClickListener {
            try {
                val url = getString(R.string.privacy_policy_url)
                val intent = Intent(Intent.ACTION_VIEW, android.net.Uri.parse(url))
                startActivity(intent)
            } catch (e: Exception) {
                // Browser not available
            }
        }

        binding.btnViewLogs.setOnClickListener {
            LogViewerActivity.start(this)
        }

        binding.btnDownloadShareLogs.setOnClickListener {
            lifecycleScope.launch {
                Toast.makeText(this@DialerActivity, getString(R.string.msg_logs_collecting), Toast.LENGTH_SHORT).show()
                val uri = com.mhrgl.aipbx.util.AppLogManager.exportLogsToDownloads(this@DialerActivity)
                val file = com.mhrgl.aipbx.util.AppLogManager.saveLogsToFile(this@DialerActivity)
                if (uri != null) {
                    Toast.makeText(this@DialerActivity, getString(R.string.msg_logs_saved), Toast.LENGTH_SHORT).show()
                }
                com.mhrgl.aipbx.util.AppLogManager.shareLogs(this@DialerActivity, file)
            }
        }
    }

    /** Settings: full-screen incoming calls status (Android 14+ only, see #7). */
    private fun updateFullScreenCallButton() {
        if (!FullScreenCallPrompt.isRelevant) {
            binding.btnFullScreenCalls.visibility = View.GONE
            return
        }
        val allowed = FullScreenCallPrompt.isAllowed(this)
        binding.btnFullScreenCalls.visibility = View.VISIBLE
        binding.btnFullScreenCalls.text = getString(if (allowed) R.string.full_screen_status_on else R.string.full_screen_status_off)
        val color = ContextCompat.getColor(this, if (allowed) R.color.primary else R.color.status_disconnected)
        binding.btnFullScreenCalls.setTextColor(color)
        binding.btnFullScreenCalls.strokeColor = android.content.res.ColorStateList.valueOf(color)
    }

    private fun updateFcmStatusUI() {
        val fcmToken = prefs.fcmToken
        if (!fcmToken.isNullOrEmpty()) {
            binding.tvFcmStatus.text = getString(R.string.fcm_status_active)
            binding.tvFcmStatus.setTextColor(ContextCompat.getColor(this, R.color.status_connected))
        } else {
            binding.tvFcmStatus.text = getString(R.string.fcm_status_local)
            binding.tvFcmStatus.setTextColor(ContextCompat.getColor(this, R.color.text_secondary))
        }
    }

    private fun updateHeaderBadges() {
        binding.tvDndBadge.visibility = if (isDndEnabled) View.VISIBLE else View.GONE

        val hasAnyCf = currentForwardAlways.isNotEmpty() || currentForwardBusy.isNotEmpty() || currentForwardNoAnswer.isNotEmpty()
        binding.tvCfBadge.visibility = if (hasAnyCf) View.VISIBLE else View.GONE
        binding.tvCfBadge.text = when {
            currentForwardAlways.isNotEmpty() -> getString(R.string.cf_always)
            currentForwardBusy.isNotEmpty() && currentForwardNoAnswer.isNotEmpty() -> getString(R.string.cf_busy_noanswer)
            currentForwardBusy.isNotEmpty() -> getString(R.string.cf_busy)
            currentForwardNoAnswer.isNotEmpty() -> getString(R.string.cf_noanswer)
            else -> getString(R.string.ui_cf_active)
        }
    }

    private fun syncFeatures() {
        val token = prefs.token ?: return
        val baseUrl = prefs.serverUrl

        lifecycleScope.launch {
            val result = apiClient.getFeatures(baseUrl, token)
            result.onSuccess { res ->
                val f = res.features ?: return@onSuccess
                isDndEnabled = f.dndEnabled
                currentForwardAlways = f.callForwardNumber ?: ""
                currentForwardBusy = f.cfBusyNumber ?: ""
                currentForwardNoAnswer = f.cfNoAnswerNumber ?: ""
                currentNoAnswerTimeout = f.cfNoAnswerTimeout ?: 20
                // Video calls (server 1.22+): stored for the call screens, offered in the dialogs.
                prefs.videoLimits = f.video?.takeIf { it.enabled }?.let { v ->
                    val p = (v.resolution ?: "1280x720").split("x").mapNotNull { it.toIntOrNull() }
                    if (p.size == 2) com.mhrgl.aipbx.engine.VideoLimits(p[0], p[1], v.fps ?: 24) else null
                }
                applyVideoEnabled()

                binding.switchDnd.isChecked = isDndEnabled
                binding.etForwardAlways.setText(currentForwardAlways)
                binding.etForwardBusy.setText(currentForwardBusy)
                binding.etForwardNoAnswer.setText(currentForwardNoAnswer)
                binding.actvNoAnswerTimeout.setText(getString(R.string.seconds_short, currentNoAnswerTimeout), false)

                updateHeaderBadges()
            }
        }
    }

    private fun updateFeaturesOnServer(
        dnd: Boolean = isDndEnabled,
        forwardAlways: String = currentForwardAlways,
        forwardBusy: String = currentForwardBusy,
        forwardNoAnswer: String = currentForwardNoAnswer,
        noAnswerTimeout: Int = currentNoAnswerTimeout
    ) {
        val token = prefs.token ?: return
        val baseUrl = prefs.serverUrl

        lifecycleScope.launch {
            val result = apiClient.updateFeatures(
                baseUrl = baseUrl,
                token = token,
                dndEnabled = dnd,
                callForwardNumber = forwardAlways,
                cfBusyNumber = forwardBusy,
                cfNoAnswerNumber = forwardNoAnswer,
                cfNoAnswerTimeout = noAnswerTimeout
            )
            result.onSuccess { res ->
                val f = res.features
                isDndEnabled = f?.dndEnabled ?: dnd
                currentForwardAlways = f?.callForwardNumber ?: forwardAlways
                currentForwardBusy = f?.cfBusyNumber ?: forwardBusy
                currentForwardNoAnswer = f?.cfNoAnswerNumber ?: forwardNoAnswer
                currentNoAnswerTimeout = f?.cfNoAnswerTimeout ?: noAnswerTimeout

                binding.switchDnd.isChecked = isDndEnabled
                binding.etForwardAlways.setText(currentForwardAlways)
                binding.etForwardBusy.setText(currentForwardBusy)
                binding.etForwardNoAnswer.setText(currentForwardNoAnswer)
                binding.actvNoAnswerTimeout.setText(getString(R.string.seconds_short, currentNoAnswerTimeout), false)

                updateHeaderBadges()

                val msg = if (currentForwardAlways.isEmpty() && currentForwardBusy.isEmpty() && currentForwardNoAnswer.isEmpty()) {
                    getString(R.string.msg_settings_updated_no_cf)
                } else {
                    getString(R.string.msg_cf_saved)
                }
                Toast.makeText(this@DialerActivity, msg, Toast.LENGTH_SHORT).show()
            }.onFailure { err ->
                Toast.makeText(this@DialerActivity, getString(R.string.err_setting_update, err.message), Toast.LENGTH_SHORT).show()
            }
        }
    }

    private fun syncDeviceToken() {
        val token = prefs.token ?: return
        val baseUrl = prefs.serverUrl
        val fcmTok = prefs.fcmToken
        val hasRealFcm = !fcmTok.isNullOrEmpty() && !fcmTok.startsWith("device_") && !fcmTok.startsWith("test_")
        val tokenToSend = if (hasRealFcm) fcmTok!! else ""

        lifecycleScope.launch {
            val result = apiClient.registerFcmToken(
                baseUrl = baseUrl,
                token = token,
                fcmToken = tokenToSend,
                deviceId = prefs.deviceUuid,
                deviceName = "${Build.MANUFACTURER} ${Build.MODEL}"
            )
            result.onSuccess {
                updateFcmStatusUI()
                if (hasRealFcm) {
                    Toast.makeText(this@DialerActivity, getString(R.string.msg_device_updated), Toast.LENGTH_SHORT).show()
                } else {
                    binding.tvFcmStatus.text = getString(R.string.fcm_status_registered_off)
                    binding.tvFcmStatus.setTextColor(ContextCompat.getColor(this@DialerActivity, R.color.text_secondary))
                    Toast.makeText(this@DialerActivity, getString(R.string.msg_device_sent), Toast.LENGTH_SHORT).show()
                }
            }.onFailure {
                binding.tvFcmStatus.text = getString(R.string.fcm_status_sync_failed)
                binding.tvFcmStatus.setTextColor(ContextCompat.getColor(this@DialerActivity, R.color.hangup_red))
                Toast.makeText(this@DialerActivity, getString(R.string.err_sync, it.message), Toast.LENGTH_SHORT).show()
            }
        }
    }

    // ================= PERMISSIONS & ENGINE STATUS =================

    private fun checkPermissions() {
        val needed = mutableListOf<String>()
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            needed.add(Manifest.permission.RECORD_AUDIO)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
                needed.add(Manifest.permission.POST_NOTIFICATIONS)
            }
        }
        if (needed.isNotEmpty()) {
            requestPermissionLauncher.launch(needed.toTypedArray())
        }
    }

    private fun updateConnectionUI(status: ConnectionStatus) {
        when (status) {
            ConnectionStatus.CONNECTED -> {
                binding.vStatusDot.setBackgroundResource(R.drawable.circle_status)
                binding.vStatusDot.background.setTint(ContextCompat.getColor(this, R.color.status_connected))
                binding.tvStatus.text = getString(R.string.status_connected)
                binding.tvStatus.setTextColor(ContextCompat.getColor(this, R.color.status_connected))
            }
            ConnectionStatus.CONNECTING -> {
                binding.vStatusDot.setBackgroundResource(R.drawable.circle_status)
                binding.vStatusDot.background.setTint(ContextCompat.getColor(this, R.color.status_connecting))
                binding.tvStatus.text = getString(R.string.status_connecting)
                binding.tvStatus.setTextColor(ContextCompat.getColor(this, R.color.status_connecting))
            }
            ConnectionStatus.DISCONNECTED -> {
                binding.vStatusDot.setBackgroundResource(R.drawable.circle_status)
                binding.vStatusDot.background.setTint(ContextCompat.getColor(this, R.color.status_disconnected))
                binding.tvStatus.text = getString(R.string.status_disconnected)
                binding.tvStatus.setTextColor(ContextCompat.getColor(this, R.color.status_disconnected))
            }
        }
    }

    // --- SipEngineListener ---

    override fun onConnectionStatusChanged(status: ConnectionStatus) {
        runOnUiThread { updateConnectionUI(status) }
    }

    override fun onCallStatusChanged(status: CallStatus) {
        if (status == CallStatus.ACTIVE || status == CallStatus.RINGING_OUTGOING) {
            val intent = Intent(this, CallActivity::class.java)
            startActivity(intent)
        }
    }

    override fun onIncomingCall(callerName: String, callerNumber: String) {
        // Handled by Foreground Service
    }

    // --- ChatEventListener ---

    override fun onVoicemail(caller: String, durationSec: Int, newCount: Int, text: String) {
        runOnUiThread { refreshVoicemailBadge() }
    }

    override fun onNewMessage(message: ChatMessage) {
        runOnUiThread {
            if (isChatRoomOpen() && message.conversationId == currentChatConvId) {
                chatMessageAdapter.addMessage(message)
                binding.rvChatMessages.scrollToPosition(chatMessageAdapter.itemCount - 1)
                ChatWebSocketManager.instance.sendMarkRead(currentChatConvId, message.id)
            }
            if (currentTab == Tab.CHAT) {
                loadConversations()
            }
            updateChatUnreadBadge()
        }
    }

    override fun onReceipts(conversationId: Int, readUpto: Long, deliveredUpto: Long) {
        if (conversationId == currentChatConvId) {
            runOnUiThread {
                if (::chatMessageAdapter.isInitialized) chatMessageAdapter.applyReceipts(readUpto, deliveredUpto)
            }
        }
    }

    override fun onMessageDeleted(conversationId: Int, messageId: Long) {
        if (conversationId == currentChatConvId) {
            runOnUiThread {
                if (::chatMessageAdapter.isInitialized) chatMessageAdapter.markDeleted(messageId)
            }
        }
    }

    override fun onTyping(conversationId: Int, fromName: String, isTyping: Boolean) {
        if (isChatRoomOpen() && conversationId == currentChatConvId) {
            runOnUiThread {
                if (isTyping) {
                    binding.tvChatTyping.visibility = View.VISIBLE
                    binding.tvChatTyping.text = getString(R.string.typing_name, fromName)
                } else {
                    binding.tvChatTyping.visibility = View.GONE
                }
            }
        }
    }

    override fun onGroupCreated(conversation: ChatConversation) {
        runOnUiThread {
            if (currentTab == Tab.CHAT) loadConversations()
            updateChatUnreadBadge()
        }
    }

    override fun onGroupUpdated(conversationId: Int, title: String?, avatarUrl: String?, description: String?) {
        runOnUiThread {
            if (isChatRoomOpen() && conversationId == currentChatConvId) {
                loadChatRoomGroupDetails()
            }
            if (currentTab == Tab.CHAT) loadConversations()
        }
    }

    override fun onGroupMemberAdded(conversationId: Int, members: List<String>, actor: String) {
        runOnUiThread {
            if (isChatRoomOpen() && conversationId == currentChatConvId) {
                loadChatRoomGroupDetails()
            }
            if (currentTab == Tab.CHAT) loadConversations()
        }
    }

    override fun onGroupMemberRemoved(conversationId: Int, extension: String, actor: String) {
        runOnUiThread {
            if (isChatRoomOpen() && conversationId == currentChatConvId) {
                val myExt = prefs.extension ?: ""
                if (extension == myExt) {
                    Toast.makeText(this@DialerActivity, getString(R.string.msg_removed_from_group), Toast.LENGTH_LONG).show()
                    closeChatRoom()
                } else {
                    loadChatRoomGroupDetails()
                }
            }
            if (currentTab == Tab.CHAT) loadConversations()
            updateChatUnreadBadge()
        }
    }

    override fun onGroupRoleUpdated(conversationId: Int, extension: String, role: String, actor: String) {
        runOnUiThread {
            if (isChatRoomOpen() && conversationId == currentChatConvId) {
                loadChatRoomGroupDetails()
            }
            if (currentTab == Tab.CHAT) loadConversations()
        }
    }

    override fun onGroupDeleted(conversationId: Int) {
        runOnUiThread {
            if (isChatRoomOpen() && conversationId == currentChatConvId) {
                Toast.makeText(this@DialerActivity, getString(R.string.msg_group_deleted), Toast.LENGTH_LONG).show()
                closeChatRoom()
            }
            if (currentTab == Tab.CHAT) loadConversations()
            updateChatUnreadBadge()
        }
    }

    override fun onPresence(extension: String, isOnline: Boolean) {
        runOnUiThread {
            if (isChatRoomOpen()) {
                if (!currentChatIsGroup && extension == currentChatTargetExt) {
                    applyChatRoomPresence(isOnline)
                } else if (currentChatIsGroup) {
                    // The snapshot produces a separate event for every extension — refresh
                    // only if it is a member of this group (or the members are not known yet).
                    val members = currentChatGroupDetails?.participants
                    if (members == null || members.any { it.extension == extension }) {
                        loadChatRoomGroupDetails()
                    }
                }
            }

            var changed = false
            for (i in 0 until allConversations.size) {
                val c = allConversations[i]
                if (c.targetExt == extension) {
                    allConversations[i] = c.copy(targetOnline = isOnline)
                    changed = true
                }
            }
            if (changed) {
                filterConversations(binding.etChatSearch.text?.toString() ?: "")
            }
        }
    }
}