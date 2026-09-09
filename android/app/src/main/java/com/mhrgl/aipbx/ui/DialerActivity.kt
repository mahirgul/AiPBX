package com.mhrgl.aipbx.ui

import android.Manifest
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.ServiceConnection
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.os.IBinder
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import android.provider.ContactsContract
import androidx.core.widget.doAfterTextChanged
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import com.mhrgl.aipbx.BuildConfig
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.databinding.ActivityDialerBinding
import com.mhrgl.aipbx.engine.SipEngineListener
import com.mhrgl.aipbx.model.CallStatus
import com.mhrgl.aipbx.model.ConnectionStatus
import com.mhrgl.aipbx.model.ContactItem
import com.mhrgl.aipbx.service.PbxForegroundService
import com.mhrgl.aipbx.util.SamsungPowerManagerHelper

class DialerActivity : AppCompatActivity(), SipEngineListener {

    private lateinit var binding: ActivityDialerBinding
    private lateinit var prefs: AppPreferences
    private val apiClient by lazy { ApiClient { prefs } }

    private var pbxService: PbxForegroundService? = null
    private var isBound = false

    private lateinit var historyAdapter: CallHistoryAdapter
    private lateinit var contactsAdapter: ContactsAdapter

    private var currentFilter = "all"
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
            Toast.makeText(this, "Arama yapabilmek için mikrofon izni gereklidir.", Toast.LENGTH_LONG).show()
        }
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

        // Edge-to-edge WindowInsets desteği (M22)
        androidx.core.view.ViewCompat.setOnApplyWindowInsetsListener(binding.root) { view, insets ->
            val systemBars = insets.getInsets(androidx.core.view.WindowInsetsCompat.Type.systemBars())
            view.setPadding(systemBars.left, systemBars.top, systemBars.right, systemBars.bottom)
            insets
        }

        setupUserHeader()
        setupDialpad()
        setupBottomNav()
        setupHistoryTab()
        setupContactsTab()
        setupFeaturesTab()
        checkPermissions()
        checkBatteryOptimization()

        // Background initial sync
        syncFeatures()
        syncDeviceToken()
    }

    @android.annotation.SuppressLint("BatteryLife")
    private fun checkBatteryOptimization() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            val pm = getSystemService(Context.POWER_SERVICE) as? android.os.PowerManager
            if (pm != null && !pm.isIgnoringBatteryOptimizations(packageName)) {
                try {
                    val intent = Intent(android.provider.Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                        data = android.net.Uri.parse("package:$packageName")
                    }
                    startActivity(intent)
                } catch (e: Exception) {
                    // Cihaz intent'i desteklemiyorsa sessizce geç
                }
            }
        }
    }

    override fun onStart() {
        super.onStart()
        val intent = Intent(this, PbxForegroundService::class.java)
        bindService(intent, serviceConnection, Context.BIND_AUTO_CREATE)
    }

    override fun onResume() {
        super.onResume()
        if (currentTab == Tab.HISTORY) {
            loadCallHistory(currentFilter)
        }
        updateChatUnreadBadge()

        val sUrl = prefs.serverUrl
        val token = prefs.token
        if (!sUrl.isNullOrEmpty() && !token.isNullOrEmpty()) {
            com.mhrgl.aipbx.data.ChatWebSocketManager.instance.connect(sUrl, token)
        }

        if (prefs.hasSleepingWarning) {
            prefs.hasSleepingWarning = false
            AlertDialog.Builder(this)
                .setTitle("Arka Plan Çalışması Kısıtlandı!")
                .setMessage("Cihazınız ekran kapalıyken uygulamanın arka planda çalışmasını uykuya almış olabilir. Gelen aramaları kaçırmamak için lütfen pil ve arka plan kısıtlamalarını kapatın.")
                .setPositiveButton("Ayarları Düzenle") { _, _ ->
                    SamsungPowerManagerHelper.openBatterySettings(this)
                }
                .setNegativeButton("Tamam", null)
                .show()
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

    // ================= HEADER & LOGOUT =================

    private fun setupUserHeader() {
        val ext = prefs.extension ?: "--"
        val name = prefs.fullName ?: (prefs.username ?: "")
        binding.tvUserExt.text = "Dahili: $ext ($name)"

        binding.btnLogout.setOnClickListener {
            AlertDialog.Builder(this)
                .setTitle("Çıkış Yap")
                .setMessage("Santral oturumunu kapatmak istediğinize emin misiniz?")
                .setPositiveButton("Çıkış") { _, _ ->
                    pbxService?.engine?.destroy()
                    prefs.clearAuth()
                    stopService(Intent(this, PbxForegroundService::class.java))
                    startActivity(Intent(this, ServerSetupActivity::class.java))
                    finish()
                }
                .setNegativeButton("İptal", null)
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

    private enum class Tab { DIALER, HISTORY, CONTACTS, FEATURES }
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
            ChatListActivity.start(this)
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
        binding.layoutFeatures.visibility = View.GONE

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
            Tab.FEATURES -> {
                binding.layoutFeatures.visibility = View.VISIBLE
                binding.ivTabFeatures.setColorFilter(colorActive)
                binding.tvTabFeatures.setTextColor(colorActive)
            }
        }
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

        binding.btnBackspace.setOnClickListener {
            val current = binding.tvDigits.text.toString()
            if (current.isNotEmpty()) {
                binding.tvDigits.text = current.substring(0, current.length - 1)
                updateBackspaceVisibility()
            }
        }

        binding.btnBackspace.setOnLongClickListener {
            binding.tvDigits.text = ""
            updateBackspaceVisibility()
            true
        }

        binding.btnCall.setOnClickListener {
            val target = binding.tvDigits.text.toString().trim()
            if (target.isEmpty()) {
                Toast.makeText(this, "Lütfen bir numara girin", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }
            initiateCall(target)
        }
    }

    private fun appendDigit(digit: String) {
        val current = binding.tvDigits.text.toString()
        binding.tvDigits.text = current + digit
        updateBackspaceVisibility()
    }

    private fun updateBackspaceVisibility() {
        binding.btnBackspace.visibility = if (binding.tvDigits.text.isNotEmpty()) View.VISIBLE else View.INVISIBLE
    }

    private fun initiateCall(targetNumber: String, displayName: String? = null) {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            Toast.makeText(this, "Arama yapabilmek için mikrofon izni gereklidir.", Toast.LENGTH_LONG).show()
            requestPermissionLauncher.launch(arrayOf(Manifest.permission.RECORD_AUDIO))
            return
        }

        val service = pbxService
        if (service == null || service.engine.currentConnectionStatus != ConnectionStatus.CONNECTED) {
            Toast.makeText(this, "Santrale bağlı değilsiniz. Lütfen bekleyin...", Toast.LENGTH_SHORT).show()
            return
        }

        var cleanNumber = targetNumber.replace(Regex("[^0-9*#+]"), "")
        if (cleanNumber.startsWith("+90")) {
            cleanNumber = "0" + cleanNumber.substring(3)
        } else if (cleanNumber.startsWith("+")) {
            cleanNumber = "00" + cleanNumber.substring(1)
        } else if (cleanNumber.length == 10 && cleanNumber.startsWith("5")) {
            cleanNumber = "0$cleanNumber"
        }

        if (cleanNumber.isEmpty()) {
            Toast.makeText(this, "Lütfen geçerli bir numara girin", Toast.LENGTH_SHORT).show()
            return
        }

        binding.tvDigits.text = cleanNumber
        updateBackspaceVisibility()

        val domain = prefs.domain?.ifEmpty { null } ?: try {
            android.net.Uri.parse(prefs.serverUrl).host?.ifEmpty { null } ?: ""
        } catch (e: Exception) { "" }
        service.engine.makeCall(
            targetNumber = cleanNumber,
            domain = domain,
            turnUrl = prefs.turnUrl,
            turnUser = prefs.turnUsername,
            turnPass = prefs.turnCredential,
            displayName = displayName
        )

        val callIntent = Intent(this, CallActivity::class.java)
        startActivity(callIntent)
    }

    // ================= TAB 2: CALL HISTORY =================

    private fun setupHistoryTab() {
        historyAdapter = CallHistoryAdapter { targetNumber, partyName ->
            initiateCall(targetNumber, partyName)
        }
        binding.rvCallHistory.layoutManager = LinearLayoutManager(this)
        binding.rvCallHistory.adapter = historyAdapter

        binding.btnRefreshHistory.setOnClickListener {
            loadCallHistory(currentFilter)
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

                if (list.isEmpty()) {
                    binding.tvEmptyHistory.visibility = View.VISIBLE
                } else {
                    binding.tvEmptyHistory.visibility = View.GONE
                }

                response.stats?.let { s ->
                    val mins = s.totalBillsec / 60
                    binding.tvHistoryStats.text = "Toplam: ${s.totalCalls} | Cevapsız: ${s.missedCalls} | Konuşma: ${mins} dk"
                }
            }.onFailure { err ->
                Toast.makeText(this@DialerActivity, "Geçmiş yüklenemedi: ${err.message}", Toast.LENGTH_SHORT).show()
                binding.tvEmptyHistory.visibility = View.VISIBLE
            }
        }
    }

    // ================= TAB 3: CONTACTS =================

    private fun setupContactsTab() {
        contactsAdapter = ContactsAdapter { targetExt, contactName ->
            initiateCall(targetExt, contactName)
        }
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

        // READ_CONTACTS izni verilmişse cihaz rehberini arka planda önceden yükle,
        // böylece kurumsal sekmedeyken arama yapıldığında telefon kişileri de anında bulunur (§5.6)
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_CONTACTS) == PackageManager.PERMISSION_GRANTED) {
            loadDeviceContacts()
        }
    }

    private fun filterContacts(query: String) {
        contactsAdapter.filterUnified(query, corporateContactsList, deviceContactsList, isDeviceContactsSelected)
        val count = contactsAdapter.itemCount
        val label = if (query.trim().isNotEmpty()) {
            "sonuç bulundu"
        } else if (isDeviceContactsSelected) {
            "telefon kişisi listelendi"
        } else {
            "dahili listelendi"
        }
        binding.tvContactsCount.text = "$count $label"

        if (count == 0) {
            binding.tvEmptyContacts.text = if (query.trim().isNotEmpty()) {
                "'$query' ile eşleşen kişi bulunamadı."
            } else if (isDeviceContactsSelected) {
                "Telefon rehberinde kişi bulunamadı veya rehber izni verilmedi."
            } else {
                "Kurumsal dahili bulunamadı."
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
                filterContacts(binding.etContactSearch.text?.toString() ?: "")
            }.onFailure { err ->
                Toast.makeText(this@DialerActivity, "Rehber yüklenemedi: ${err.message}", Toast.LENGTH_SHORT).show()
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
                            val name = if (nameIdx >= 0) c.getString(nameIdx) ?: "İsimsiz" else "İsimsiz"
                            val rawNum = if (numIdx >= 0) c.getString(numIdx) ?: "" else ""
                            val cleanNum = rawNum.replace(Regex("[^0-9+]"), "")
                            if (cleanNum.isNotEmpty() && !seenNumbers.contains(cleanNum)) {
                                seenNumbers.add(cleanNum)
                                contactsList.add(
                                    ContactItem(
                                        extension = cleanNum,
                                        name = name,
                                        role = "Cihaz Rehberi",
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

    // ================= TAB 4: FEATURES (DND & CF) =================

    private fun setupFeaturesTab() {
        // 1. Sürüm ve Sistem Bilgileri
        binding.tvAppVersion.text = "Uygulama: v${BuildConfig.VERSION_NAME} (Build ${BuildConfig.VERSION_CODE})"
        binding.tvDeviceInfo.text = "Cihaz: ${Build.MANUFACTURER} ${Build.MODEL} (Android ${Build.VERSION.RELEASE}, API ${Build.VERSION.SDK_INT})"
        val serverUrl = prefs.serverUrl
        binding.tvServerVersion.text = "Santral: $serverUrl"

        // FCM Durumu
        updateFcmStatusUI()

        // Sunucudan güncel sürüm/marka bilgisini ping ile çekip göster
        if (serverUrl.isNotEmpty()) {
            lifecycleScope.launch {
                val pingRes = apiClient.ping(serverUrl)
                pingRes.onSuccess { info ->
                    val brand = info.brandTitle ?: "AI PBX"
                    val ver = info.version ?: "1.0"
                    binding.tvServerVersion.text = "Santral: $brand v$ver ($serverUrl)"
                }
            }
        }

        // 2. Süre Açılır Listesi (Dropdown)
        val timeoutAdapter = ArrayAdapter(this, android.R.layout.simple_dropdown_item_1line, timeoutOptions.map { "$it sn" })
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

        // 4. Yönlendirmeleri Kaydet
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

        // 5. Yönlendirmeleri Temizle
        binding.btnClearForward.setOnClickListener {
            binding.etForwardAlways.setText("")
            binding.etForwardBusy.setText("")
            binding.etForwardNoAnswer.setText("")
            binding.actvNoAnswerTimeout.setText("20 sn", false)
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

        binding.btnBatteryOptimization.setOnClickListener {
            if (SamsungPowerManagerHelper.isSamsungDevice) {
                AlertDialog.Builder(this)
                    .setTitle(SamsungPowerManagerHelper.SAMSUNG_GUIDE_TITLE)
                    .setMessage(SamsungPowerManagerHelper.SAMSUNG_GUIDE_MESSAGE)
                    .setPositiveButton("Ayarları Aç") { _, _ ->
                        SamsungPowerManagerHelper.openBatterySettings(this)
                    }
                    .setNegativeButton("Kapat", null)
                    .show()
            } else {
                AlertDialog.Builder(this)
                    .setTitle("Pil Optimizasyonu Ayarları")
                    .setMessage("Ekran kapalıyken arka planda çağrı kaçırmamak için uygulamanın pil tasarrufundan muaf (Kısıtlamasız / Optimize edilmemiş) olduğundan emin olun.")
                    .setPositiveButton("Ayarları Aç") { _, _ ->
                        SamsungPowerManagerHelper.openBatterySettings(this)
                    }
                    .setNegativeButton("Kapat", null)
                    .show()
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
    }

    private fun updateFcmStatusUI() {
        val fcmToken = prefs.fcmToken
        if (!fcmToken.isNullOrEmpty()) {
            binding.tvFcmStatus.text = "Bildirim: FCM Uyandırma Aktif"
            binding.tvFcmStatus.setTextColor(ContextCompat.getColor(this, R.color.status_connected))
        } else {
            binding.tvFcmStatus.text = "Bildirim: Yerel Servis Modu (FCM Yok)"
            binding.tvFcmStatus.setTextColor(ContextCompat.getColor(this, R.color.text_secondary))
        }
    }

    private fun updateHeaderBadges() {
        binding.tvDndBadge.visibility = if (isDndEnabled) View.VISIBLE else View.GONE

        val hasAnyCf = currentForwardAlways.isNotEmpty() || currentForwardBusy.isNotEmpty() || currentForwardNoAnswer.isNotEmpty()
        binding.tvCfBadge.visibility = if (hasAnyCf) View.VISIBLE else View.GONE
        binding.tvCfBadge.text = when {
            currentForwardAlways.isNotEmpty() -> "CF (HER ZAMAN)"
            currentForwardBusy.isNotEmpty() && currentForwardNoAnswer.isNotEmpty() -> "CF (MEŞGUL/CEVAPSIZ)"
            currentForwardBusy.isNotEmpty() -> "CF (MEŞGUL)"
            currentForwardNoAnswer.isNotEmpty() -> "CF (CEVAPSIZ)"
            else -> "CF AKTİF"
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

                binding.switchDnd.isChecked = isDndEnabled
                binding.etForwardAlways.setText(currentForwardAlways)
                binding.etForwardBusy.setText(currentForwardBusy)
                binding.etForwardNoAnswer.setText(currentForwardNoAnswer)
                binding.actvNoAnswerTimeout.setText("$currentNoAnswerTimeout sn", false)

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
                binding.actvNoAnswerTimeout.setText("$currentNoAnswerTimeout sn", false)

                updateHeaderBadges()

                val msg = if (currentForwardAlways.isEmpty() && currentForwardBusy.isEmpty() && currentForwardNoAnswer.isEmpty()) {
                    "Santral ayarları güncellendi (Yönlendirmeler kapalı)"
                } else {
                    "Çağrı yönlendirme ayarları başarıyla kaydedildi"
                }
                Toast.makeText(this@DialerActivity, msg, Toast.LENGTH_SHORT).show()
            }.onFailure { err ->
                Toast.makeText(this@DialerActivity, "Ayar güncellenemedi: ${err.message}", Toast.LENGTH_SHORT).show()
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
                    Toast.makeText(this@DialerActivity, "Cihaz ve bildirim bilgisi güncellendi", Toast.LENGTH_SHORT).show()
                } else {
                    binding.tvFcmStatus.text = "Cihaz Kayıtlı (FCM Kapalı)"
                    binding.tvFcmStatus.setTextColor(ContextCompat.getColor(this@DialerActivity, R.color.text_secondary))
                    Toast.makeText(this@DialerActivity, "Cihaz bilgisi sunucuya iletildi", Toast.LENGTH_SHORT).show()
                }
            }.onFailure {
                binding.tvFcmStatus.text = "Cihaz Senkronizasyonu Başarısız"
                binding.tvFcmStatus.setTextColor(ContextCompat.getColor(this@DialerActivity, R.color.hangup_red))
                Toast.makeText(this@DialerActivity, "Senkronizasyon hatası: ${it.message}", Toast.LENGTH_SHORT).show()
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
}