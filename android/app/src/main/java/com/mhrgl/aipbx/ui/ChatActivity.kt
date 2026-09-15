package com.mhrgl.aipbx.ui

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.widget.*
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.data.ChatEventListener
import com.mhrgl.aipbx.data.ChatWebSocketManager
import com.mhrgl.aipbx.databinding.DialogGroupInfoBinding
import com.mhrgl.aipbx.model.ChatConversation
import com.mhrgl.aipbx.model.ChatMessage
import com.mhrgl.aipbx.model.ChatParticipant
import com.mhrgl.aipbx.model.ChatUploadResponse
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.io.FileOutputStream

class ChatActivity : AppCompatActivity(), ChatEventListener {

    companion object {
        const val EXTRA_CONV_ID = "extra_conv_id"
        const val EXTRA_TARGET_EXT = "extra_target_ext"
        const val EXTRA_TARGET_NAME = "extra_target_name"
        const val EXTRA_IS_GROUP = "extra_is_group"

        @Volatile
        var activeConversationId: Int = 0

        fun start(
            context: Context,
            convId: Int,
            targetExt: String = "",
            targetName: String? = null,
            isGroup: Boolean = false
        ) {
            val intent = Intent(context, ChatActivity::class.java).apply {
                putExtra(EXTRA_CONV_ID, convId)
                putExtra(EXTRA_TARGET_EXT, targetExt)
                putExtra(EXTRA_TARGET_NAME, targetName)
                putExtra(EXTRA_IS_GROUP, isGroup)
            }
            context.startActivity(intent)
        }
    }

    private var convId: Int = 0
    private var targetExt: String = ""
    private var targetName: String = ""
    private var isGroup: Boolean = false
    private var currentGroupDetails: ChatConversation? = null

    private lateinit var prefs: AppPreferences
    private lateinit var apiClient: ApiClient
    private lateinit var adapter: ChatMessageAdapter

    private lateinit var rvMessages: RecyclerView
    private lateinit var etMessage: EditText
    private lateinit var btnSend: ImageButton
    private lateinit var btnAttach: ImageButton
    private lateinit var btnCamera: ImageButton
    private lateinit var tvTyping: TextView
    private lateinit var llUploadPreview: LinearLayout
    private lateinit var tvUploadFilename: TextView
    private lateinit var btnCancelUpload: ImageButton

    private var pendingUpload: ChatUploadResponse? = null

    // File pickers
    private val pickDocumentLauncher = registerForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) handlePickedUri(uri, "file")
    }

    private val pickPhotoLauncher = registerForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) handlePickedUri(uri, "image")
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_chat)

        convId = intent.getIntExtra(EXTRA_CONV_ID, 0)
        targetExt = intent.getStringExtra(EXTRA_TARGET_EXT) ?: ""
        targetName = intent.getStringExtra(EXTRA_TARGET_NAME) ?: targetExt
        isGroup = intent.getBooleanExtra(EXTRA_IS_GROUP, false)

        prefs = AppPreferences.getInstance(this)
        apiClient = ApiClient { prefs }

        setupViews()
        setupHeader()
        setupRecyclerView()
        ensureConversationAndLoadMessages()

        ChatWebSocketManager.instance.addListener(this)
        val sUrl = prefs.serverUrl
        val token = prefs.token
        if (!sUrl.isNullOrEmpty() && !token.isNullOrEmpty()) {
            ChatWebSocketManager.instance.connect(sUrl, token)
        }
    }

    override fun onResume() {
        super.onResume()
        if (convId > 0) {
            activeConversationId = convId
            val nm = getSystemService(Context.NOTIFICATION_SERVICE) as? android.app.NotificationManager
            nm?.cancel(10000 + (convId % 1000))
        }
    }

    override fun onPause() {
        super.onPause()
        if (activeConversationId == convId) {
            activeConversationId = 0
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        if (activeConversationId == convId) {
            activeConversationId = 0
        }
        ChatWebSocketManager.instance.removeListener(this)
    }

    private fun setupViews() {
        rvMessages = findViewById(R.id.rvMessages)
        etMessage = findViewById(R.id.etMessage)
        btnSend = findViewById(R.id.btnSend)
        btnAttach = findViewById(R.id.btnAttach)
        btnCamera = findViewById(R.id.btnCamera)
        tvTyping = findViewById(R.id.tvTyping)
        llUploadPreview = findViewById(R.id.llUploadPreview)
        tvUploadFilename = findViewById(R.id.tvUploadFilename)
        btnCancelUpload = findViewById(R.id.btnCancelUpload)

        findViewById<ImageButton>(R.id.btnBack).setOnClickListener {
            finish()
        }

        btnSend.setOnClickListener {
            sendMessage()
        }

        btnAttach.setOnClickListener {
            pickDocumentLauncher.launch("*/*")
        }

        btnCamera.setOnClickListener {
            pickPhotoLauncher.launch("image/*")
        }

        btnCancelUpload.setOnClickListener {
            pendingUpload = null
            llUploadPreview.visibility = View.GONE
        }
    }

    private fun setupHeader() {
        val tvName = findViewById<TextView>(R.id.tvTargetName)
        val tvAvatar = findViewById<TextView>(R.id.tvTargetAvatar)
        val tvStatus = findViewById<TextView>(R.id.tvTargetStatus)
        val vOnlineDot = findViewById<View>(R.id.vTargetOnlineDot)
        val btnCall = findViewById<ImageButton>(R.id.btnCall)
        val btnGroupInfo = findViewById<ImageButton>(R.id.btnGroupInfo)
        val llHeader = findViewById<View>(R.id.llHeaderInfo)

        if (isGroup) {
            btnCall.visibility = View.GONE
            btnGroupInfo.visibility = View.VISIBLE
            vOnlineDot.visibility = View.GONE

            tvAvatar.text = "👥"
            tvAvatar.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF4F46E5.toInt())
            tvName.text = if (targetName.isNotEmpty()) targetName else "Grup Sohbeti"
            tvStatus.text = "Grup"

            btnGroupInfo.setOnClickListener { showGroupInfoDialog() }
            llHeader.setOnClickListener { showGroupInfoDialog() }

            loadGroupDetails()
        } else {
            btnCall.visibility = View.VISIBLE
            btnGroupInfo.visibility = View.GONE
            vOnlineDot.visibility = View.VISIBLE

            tvName.text = if (targetName.isNotEmpty()) "$targetName (#$targetExt)" else "Dahili #$targetExt"
            tvAvatar.text = targetName.take(1).uppercase()
            tvAvatar.backgroundTintList = null

            btnCall.setOnClickListener {
                if (targetExt.isNotEmpty()) {
                    val intent = Intent(this, DialerActivity::class.java).apply {
                        flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
                        putExtra("extra_dial_number", targetExt)
                    }
                    startActivity(intent)
                }
            }
        }
    }

    private fun loadGroupDetails() {
        if (convId <= 0) return
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            val res = apiClient.getGroupDetails(sUrl, token, convId)
            res.onSuccess { conv ->
                currentGroupDetails = conv
                isGroup = true
                runOnUiThread {
                    adapter.setIsGroup(true)
                    val tvAvatar = findViewById<TextView>(R.id.tvTargetAvatar)
                    tvAvatar.text = "👥"
                    tvAvatar.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF4F46E5.toInt())
                    findViewById<ImageButton>(R.id.btnCall).visibility = View.GONE
                    findViewById<ImageButton>(R.id.btnGroupInfo).visibility = View.VISIBLE
                    findViewById<View>(R.id.vTargetOnlineDot).visibility = View.GONE
                    findViewById<TextView>(R.id.tvTargetName).text = conv.title ?: "Grup Sohbeti"
                    findViewById<TextView>(R.id.tvTargetStatus).text =
                        "${conv.memberCount} üye, ${conv.onlineCount} çevrimiçi"

                    val llHeader = findViewById<View>(R.id.llHeaderInfo)
                    val btnGroupInfo = findViewById<ImageButton>(R.id.btnGroupInfo)
                    btnGroupInfo.setOnClickListener { showGroupInfoDialog() }
                    llHeader.setOnClickListener { showGroupInfoDialog() }
                }
            }
        }
    }

    private fun setupRecyclerView() {
        val myExt = prefs.extension ?: ""
        val baseUrl = prefs.serverUrl ?: ""

        adapter = ChatMessageAdapter(myExt, baseUrl, isGroup)
        val lm = LinearLayoutManager(this).apply {
            stackFromEnd = true
        }
        rvMessages.layoutManager = lm
        rvMessages.adapter = adapter
    }

    private fun ensureConversationAndLoadMessages() {
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            if (!isGroup && convId <= 0 && targetExt.isNotEmpty()) {
                val createRes = apiClient.createDirectChat(sUrl, token, targetExt)
                createRes.onSuccess { conv ->
                    convId = conv.id
                    activeConversationId = conv.id
                    val nm = getSystemService(Context.NOTIFICATION_SERVICE) as? android.app.NotificationManager
                    nm?.cancel(10000 + (convId % 1000))
                    loadMessages()
                }.onFailure {
                    Toast.makeText(this@ChatActivity, "Sohbet oluşturulamadı: ${it.message}", Toast.LENGTH_SHORT).show()
                }
            } else if (convId > 0) {
                loadMessages()
                if (isGroup || targetExt.isEmpty()) loadGroupDetails()
            }
        }
    }

    private fun loadMessages() {
        if (convId <= 0) return
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            val res = apiClient.getChatMessages(sUrl, token, convId, 50)
            res.onSuccess { list ->
                adapter.submitList(list)
                rvMessages.scrollToPosition((list.size - 1).coerceAtLeast(0))
                ChatWebSocketManager.instance.sendMarkRead(convId, list.lastOrNull()?.id ?: 0L)
            }
        }
    }

    private fun sendMessage() {
        val text = etMessage.text.toString().trim()
        val upload = pendingUpload

        if (text.isEmpty() && upload == null) return
        if (convId <= 0) return

        val msgType = upload?.msgType ?: "text"
        val attachUrl = upload?.attachmentUrl
        val fileName = upload?.fileName
        val fileSize = upload?.fileSize ?: 0L
        val mimeType = upload?.mimeType

        ChatWebSocketManager.instance.sendMessage(
            convId = convId,
            msgType = msgType,
            message = text,
            attachmentUrl = attachUrl,
            fileName = fileName,
            fileSize = fileSize,
            mimeType = mimeType
        )

        etMessage.setText("")
        pendingUpload = null
        llUploadPreview.visibility = View.GONE
    }

    private fun handlePickedUri(uri: Uri, type: String) {
        lifecycleScope.launch {
            llUploadPreview.visibility = View.VISIBLE
            tvUploadFilename.text = "Dosya hazırlanıyor ve yükleniyor..."

            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            val tempFile = withContext(Dispatchers.IO) {
                try {
                    val cr = contentResolver
                    val mime = cr.getType(uri) ?: if (type == "image") "image/jpeg" else "application/octet-stream"
                    val ext = if (type == "image") ".jpg" else ".bin"
                    val file = File.createTempFile("chat_upload_", ext, cacheDir)

                    cr.openInputStream(uri)?.use { input ->
                        FileOutputStream(file).use { output ->
                            input.copyTo(output)
                        }
                    }
                    Pair(file, mime)
                } catch (e: Exception) {
                    null
                }
            }

            if (tempFile == null) {
                Toast.makeText(this@ChatActivity, "Dosya okunamadı.", Toast.LENGTH_SHORT).show()
                llUploadPreview.visibility = View.GONE
                return@launch
            }

            val (file, mime) = tempFile
            val uploadRes = apiClient.uploadChatFile(sUrl, token, file, mime)
            uploadRes.onSuccess { res ->
                pendingUpload = res
                tvUploadFilename.text = res.fileName ?: file.name
            }.onFailure {
                Toast.makeText(this@ChatActivity, "Yükleme hatası: ${it.message}", Toast.LENGTH_LONG).show()
                llUploadPreview.visibility = View.GONE
            }
        }
    }

    // --- Group Info Dialog ---

    private fun showGroupInfoDialog() {
        if (!isGroup || convId <= 0) return

        lifecycleScope.launch {
            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            val res = apiClient.getGroupDetails(sUrl, token, convId)
            val group = res.getOrNull() ?: currentGroupDetails
            if (group == null) {
                Toast.makeText(this@ChatActivity, "Grup detayları yüklenemedi.", Toast.LENGTH_SHORT).show()
                return@launch
            }
            currentGroupDetails = group

            val binding = DialogGroupInfoBinding.inflate(layoutInflater)
            val dialog = AlertDialog.Builder(this@ChatActivity)
                .setView(binding.root)
                .create()

            val myExt = prefs.extension ?: ""
            val isAdmin = group.myRole.equals("admin", ignoreCase = true)

            // Populate view
            binding.tvGroupInfoAvatar.text = "👥"
            binding.tvGroupInfoAvatar.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF4F46E5.toInt())
            binding.tvGroupInfoTitle.text = group.title ?: "Grup Sohbeti"
            binding.tvGroupInfoSubtitle.text = "${group.memberCount} üye • ${group.onlineCount} çevrimiçi"
            if (!group.description.isNullOrEmpty()) {
                binding.tvGroupInfoDesc.visibility = View.VISIBLE
                binding.tvGroupInfoDesc.text = group.description
            } else {
                binding.tvGroupInfoDesc.visibility = View.GONE
            }

            if (isAdmin) {
                binding.llAdminActions.visibility = View.VISIBLE
                binding.btnDeleteGroup.visibility = View.VISIBLE
            } else {
                binding.llAdminActions.visibility = View.GONE
                binding.btnDeleteGroup.visibility = View.GONE
            }

            // Participants adapter
            val participantAdapter = GroupParticipantAdapter(
                myExtension = myExt,
                isAdmin = isAdmin,
                onToggleAdminRole = { participant ->
                    val newRole = if (participant.role == "admin") "member" else "admin"
                    lifecycleScope.launch {
                        val roleRes = apiClient.updateGroupMemberRole(sUrl, token, convId, participant.extension, newRole)
                        roleRes.onSuccess {
                            dialog.dismiss()
                            showGroupInfoDialog()
                            loadGroupDetails()
                        }.onFailure {
                            Toast.makeText(this@ChatActivity, "Yetki değiştirilemedi: ${it.message}", Toast.LENGTH_SHORT).show()
                        }
                    }
                },
                onRemoveMember = { participant ->
                    AlertDialog.Builder(this@ChatActivity)
                        .setTitle("Üyeyi Çıkar")
                        .setMessage("${participant.name ?: participant.extension} gruptan çıkarılsın mı?")
                        .setPositiveButton("Çıkar") { _, _ ->
                            lifecycleScope.launch {
                                val remRes = apiClient.removeGroupMember(sUrl, token, convId, participant.extension)
                                remRes.onSuccess {
                                    dialog.dismiss()
                                    showGroupInfoDialog()
                                    loadGroupDetails()
                                }.onFailure {
                                    Toast.makeText(this@ChatActivity, "Üye çıkarılamadı: ${it.message}", Toast.LENGTH_SHORT).show()
                                }
                            }
                        }
                        .setNegativeButton("İptal", null)
                        .show()
                }
            )

            binding.rvGroupParticipants.layoutManager = LinearLayoutManager(this@ChatActivity)
            binding.rvGroupParticipants.adapter = participantAdapter
            participantAdapter.submitList(group.participants ?: emptyList())

            // Edit Group Info
            binding.btnEditGroupInfo.setOnClickListener {
                showEditGroupInfoDialog(group) {
                    dialog.dismiss()
                    showGroupInfoDialog()
                    loadGroupDetails()
                }
            }

            // Add Member
            binding.btnAddMember.setOnClickListener {
                showAddMembersDialog(group) {
                    dialog.dismiss()
                    showGroupInfoDialog()
                    loadGroupDetails()
                }
            }

            // Leave Group
            binding.btnLeaveGroup.setOnClickListener {
                AlertDialog.Builder(this@ChatActivity)
                    .setTitle("Gruptan Ayrıl")
                    .setMessage("Bu gruptan ayrılmak istediğinizden emin misiniz?")
                    .setPositiveButton("Ayrıl") { _, _ ->
                        lifecycleScope.launch {
                            val leaveRes = apiClient.leaveGroup(sUrl, token, convId)
                            leaveRes.onSuccess {
                                Toast.makeText(this@ChatActivity, "Gruptan ayrıldınız.", Toast.LENGTH_SHORT).show()
                                dialog.dismiss()
                                finish()
                            }.onFailure {
                                Toast.makeText(this@ChatActivity, "İşlem başarısız: ${it.message}", Toast.LENGTH_LONG).show()
                            }
                        }
                    }
                    .setNegativeButton("Vazgeç", null)
                    .show()
            }

            // Delete Group
            binding.btnDeleteGroup.setOnClickListener {
                AlertDialog.Builder(this@ChatActivity)
                    .setTitle("Grubu Sil")
                    .setMessage("Bu grubu silmek istediğinizden emin misiniz? Tüm üyelerin sohbet listesinden kaldırılacaktır.")
                    .setPositiveButton("Sil") { _, _ ->
                        lifecycleScope.launch {
                            val delRes = apiClient.deleteGroup(sUrl, token, convId)
                            delRes.onSuccess {
                                Toast.makeText(this@ChatActivity, "Grup silindi.", Toast.LENGTH_SHORT).show()
                                dialog.dismiss()
                                finish()
                            }.onFailure {
                                Toast.makeText(this@ChatActivity, "Silinemedi: ${it.message}", Toast.LENGTH_LONG).show()
                            }
                        }
                    }
                    .setNegativeButton("Vazgeç", null)
                    .show()
            }

            binding.btnCloseGroupInfo.setOnClickListener {
                dialog.dismiss()
            }

            dialog.show()
        }
    }

    private fun showEditGroupInfoDialog(group: ChatConversation, onUpdated: () -> Unit) {
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
        btnSubmit.text = "Kaydet"

        etTitle.setText(group.title ?: "")
        etDesc.setText(group.description ?: "")

        val dialog = AlertDialog.Builder(this)
            .setTitle("Grup Bilgilerini Düzenle")
            .setView(view)
            .create()

        btnCancel.setOnClickListener { dialog.dismiss() }

        btnSubmit.setOnClickListener {
            val newTitle = etTitle.text.toString().trim()
            val newDesc = etDesc.text.toString().trim()
            if (newTitle.isEmpty()) {
                Toast.makeText(this, "Grup adı zorunludur.", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            val sUrl = prefs.serverUrl ?: return@setOnClickListener
            val token = prefs.token ?: return@setOnClickListener

            btnSubmit.isEnabled = false
            lifecycleScope.launch {
                val updateRes = apiClient.updateGroupInfo(
                    baseUrl = sUrl,
                    token = token,
                    convId = group.id,
                    title = newTitle,
                    description = if (newDesc.isEmpty()) null else newDesc
                )
                updateRes.onSuccess {
                    dialog.dismiss()
                    onUpdated()
                }.onFailure {
                    btnSubmit.isEnabled = true
                    Toast.makeText(this@ChatActivity, "Güncellenemedi: ${it.message}", Toast.LENGTH_SHORT).show()
                }
            }
        }

        dialog.show()
    }

    private fun showAddMembersDialog(group: ChatConversation, onAdded: () -> Unit) {
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            val contactsRes = apiClient.getContacts(sUrl, token)
            val contacts = contactsRes.getOrNull()?.contacts ?: emptyList()

            val existingExts = (group.participants ?: emptyList()).map { it.extension }.toSet()
            val availableContacts = contacts.filter { !existingExts.contains(it.extension) }

            if (availableContacts.isEmpty()) {
                Toast.makeText(this@ChatActivity, "Eklenebilecek yeni dahili bulunamadı.", Toast.LENGTH_SHORT).show()
                return@launch
            }

            val view = LayoutInflater.from(this@ChatActivity).inflate(R.layout.dialog_new_group, null)
            val etTitle = view.findViewById<EditText>(R.id.etGroupTitle)
            val etDesc = view.findViewById<EditText>(R.id.etGroupDesc)
            val tvSelected = view.findViewById<TextView>(R.id.tvSelectedCount)
            val etSearch = view.findViewById<EditText>(R.id.etSearchMember)
            val rvMembers = view.findViewById<RecyclerView>(R.id.rvGroupMembers)
            val btnSubmit = view.findViewById<Button>(R.id.btnSubmitNewGroup)
            val btnCancel = view.findViewById<Button>(R.id.btnCancelNewGroup)

            etTitle.visibility = View.GONE
            etDesc.visibility = View.GONE
            btnSubmit.text = "Üyeleri Ekle"

            val selectionAdapter = ContactSelectionAdapter { selected ->
                tvSelected.text = "Üye Seçin (${selected.size} seçildi):"
            }
            rvMembers.layoutManager = LinearLayoutManager(this@ChatActivity)
            rvMembers.adapter = selectionAdapter
            selectionAdapter.submitList(availableContacts)

            etSearch.addTextChangedListener(object : android.text.TextWatcher {
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {
                    selectionAdapter.filter(s?.toString() ?: "")
                }
                override fun afterTextChanged(s: android.text.Editable?) {}
            })

            val dialog = AlertDialog.Builder(this@ChatActivity)
                .setTitle("Gruba Üye Ekle")
                .setView(view)
                .create()

            btnCancel.setOnClickListener { dialog.dismiss() }

            btnSubmit.setOnClickListener {
                val selected = selectionAdapter.getSelectedExtensions().toList()
                if (selected.isEmpty()) {
                    Toast.makeText(this@ChatActivity, "Lütfen en az bir üye seçin.", Toast.LENGTH_SHORT).show()
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
                        Toast.makeText(this@ChatActivity, "Üyeler eklenemedi: ${it.message}", Toast.LENGTH_SHORT).show()
                    }
                }
            }

            dialog.show()
        }
    }

    // --- ChatEventListener Callbacks ---

    override fun onNewMessage(message: ChatMessage) {
        if (message.conversationId == convId) {
            runOnUiThread {
                adapter.addMessage(message)
                rvMessages.scrollToPosition(adapter.itemCount - 1)
                ChatWebSocketManager.instance.sendMarkRead(convId, message.id)
            }
        }
    }

    override fun onTyping(conversationId: Int, fromName: String, isTyping: Boolean) {
        if (conversationId == convId) {
            runOnUiThread {
                if (isTyping) {
                    tvTyping.visibility = View.VISIBLE
                    tvTyping.text = "$fromName yazıyor..."
                } else {
                    tvTyping.visibility = View.GONE
                }
            }
        }
    }

    override fun onPresence(extension: String, isOnline: Boolean) {
        if (!isGroup && extension == targetExt) {
            runOnUiThread {
                val tvStatus = findViewById<TextView>(R.id.tvTargetStatus)
                val dot = findViewById<View>(R.id.vTargetOnlineDot)
                tvStatus.text = if (isOnline) "Çevrimiçi" else "Çevrimdışı"
                tvStatus.setTextColor(if (isOnline) 0xFF10B981.toInt() else 0xFF64748B.toInt())
                dot.backgroundTintList = android.content.res.ColorStateList.valueOf(
                    if (isOnline) 0xFF10B981.toInt() else 0xFF9CA3AF.toInt()
                )
            }
        } else if (isGroup) {
            loadGroupDetails()
        }
    }

    override fun onGroupUpdated(conversationId: Int, title: String?, avatarUrl: String?, description: String?) {
        if (conversationId == convId) {
            loadGroupDetails()
        }
    }

    override fun onGroupMemberAdded(conversationId: Int, members: List<String>, actor: String) {
        if (conversationId == convId) {
            loadGroupDetails()
        }
    }

    override fun onGroupMemberRemoved(conversationId: Int, extension: String, actor: String) {
        if (conversationId == convId) {
            val myExt = prefs.extension ?: ""
            if (extension == myExt) {
                runOnUiThread {
                    Toast.makeText(this, "Gruptan çıkarıldınız.", Toast.LENGTH_LONG).show()
                    finish()
                }
            } else {
                loadGroupDetails()
            }
        }
    }

    override fun onGroupRoleUpdated(conversationId: Int, extension: String, role: String, actor: String) {
        if (conversationId == convId) {
            loadGroupDetails()
        }
    }

    override fun onGroupDeleted(conversationId: Int) {
        if (conversationId == convId) {
            runOnUiThread {
                Toast.makeText(this, "Grup silindi.", Toast.LENGTH_LONG).show()
                finish()
            }
        }
    }
}
