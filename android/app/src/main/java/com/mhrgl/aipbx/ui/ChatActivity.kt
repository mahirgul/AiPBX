package com.mhrgl.aipbx.ui

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.widget.*
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsAnimationCompat
import androidx.core.view.WindowInsetsCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.data.ChatEventListener
import com.mhrgl.aipbx.data.ChatUploadPrep
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

    // Camera: a shot photo goes through the same prepare/upload flow as one from the gallery.
    private val takeChatPhotoLauncher = registerForActivityResult(ActivityResultContracts.TakePicture()) { ok ->
        if (ok) handlePickedUri(ChatUploadPrep.cameraUri(this), "image")
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
                2 -> { pickPhotoLauncher.launch("image/*"); true }
                else -> false
            }
        }
        popup.show()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_chat)

        findViewById<View>(R.id.rootChat)?.let { root ->
            ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
                val systemBars = insets.getInsets(
                    WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
                )
                val ime = insets.getInsets(WindowInsetsCompat.Type.ime())
                val bottom = maxOf(systemBars.bottom, ime.bottom)
                view.setPadding(systemBars.left, systemBars.top, systemBars.right, bottom)
                insets
            }

            ViewCompat.setWindowInsetsAnimationCallback(
                root,
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
                        root.setPadding(systemBars.left, systemBars.top, systemBars.right, bottom)
                        return insets
                    }
                }
            )
        }

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
        sendTypingState(false)
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

        btnCamera.setOnClickListener { anchor ->
            showChatPhotoSourceMenu(anchor)
        }

        btnCancelUpload.setOnClickListener {
            pendingUpload = null
            llUploadPreview.visibility = View.GONE
        }

        etMessage.addTextChangedListener(object : android.text.TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: android.text.Editable?) {
                sendTypingState(!s.isNullOrEmpty())
            }
        })

        etMessage.setOnFocusChangeListener { _, hasFocus ->
            if (hasFocus && ::adapter.isInitialized && adapter.itemCount > 0) {
                rvMessages.postDelayed({
                    rvMessages.scrollToPosition(adapter.itemCount - 1)
                }, 150)
            }
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
            tvName.text = if (targetName.isNotEmpty()) targetName else getString(R.string.group_chat)
            tvStatus.text = getString(R.string.ui_group)

            btnGroupInfo.setOnClickListener { showGroupInfoDialog() }
            llHeader.setOnClickListener { showGroupInfoDialog() }

            loadGroupDetails()
        } else {
            btnCall.visibility = View.VISIBLE
            btnGroupInfo.visibility = View.GONE
            vOnlineDot.visibility = View.VISIBLE

            tvName.text = if (targetName.isNotEmpty()) getString(R.string.chat_name_ext_title, targetName, targetExt) else getString(R.string.chat_extension_title, targetExt)
            tvAvatar.text = targetName.take(1).uppercase()
            tvAvatar.backgroundTintList = null

            // The title was updated only by presence EVENTS; if the other side
            // was already connected (the event long gone) it always stayed
            // "Offline". The initial state comes from the snapshot the socket knows.
            applyTargetPresence(ChatWebSocketManager.instance.isOnline(targetExt))

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
                    findViewById<TextView>(R.id.tvTargetName).text = conv.title ?: getString(R.string.group_chat)
                    findViewById<TextView>(R.id.tvTargetStatus).text =
                        getString(R.string.group_members_online_conv, conv.memberCount, conv.onlineCount)

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

        adapter = ChatMessageAdapter(myExt, baseUrl, isGroup) { prefs.token }
        val lm = LinearLayoutManager(this).apply {
            stackFromEnd = true
        }
        rvMessages.layoutManager = lm
        rvMessages.adapter = adapter

        rvMessages.addOnLayoutChangeListener { _, _, _, _, bottom, _, _, _, oldBottom ->
            if (bottom < oldBottom && ::adapter.isInitialized && adapter.itemCount > 0) {
                rvMessages.post {
                    rvMessages.scrollToPosition(adapter.itemCount - 1)
                }
            }
        }
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
                    Toast.makeText(this@ChatActivity, getString(R.string.err_chat_create, it.message), Toast.LENGTH_SHORT).show()
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

    private val typingHandler = android.os.Handler(android.os.Looper.getMainLooper())
    private var typingSent = false
    private var typingSentAt = 0L
    private val stopTyping = Runnable { sendTypingState(false) }

    /** "typing" is refreshed at most every 3 s and cleared after 4 s without input. */
    private fun sendTypingState(typing: Boolean) {
        if (convId <= 0) return
        typingHandler.removeCallbacks(stopTyping)
        if (typing) {
            val now = android.os.SystemClock.elapsedRealtime()
            if (!typingSent || now - typingSentAt > 3000) {
                ChatWebSocketManager.instance.sendTyping(convId, true)
                typingSent = true
                typingSentAt = now
            }
            typingHandler.postDelayed(stopTyping, 4000)
        } else if (typingSent) {
            ChatWebSocketManager.instance.sendTyping(convId, false)
            typingSent = false
        }
    }

    private fun sendMessage() {
        sendTypingState(false)
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
            tvUploadFilename.text = getString(R.string.msg_uploading_file)

            val sUrl = prefs.serverUrl ?: return@launch
            val token = prefs.token ?: return@launch

            val prepared = withContext(Dispatchers.IO) {
                ChatUploadPrep.prepare(this@ChatActivity, uri, type)
            }

            if (prepared == null) {
                Toast.makeText(this@ChatActivity, getString(R.string.err_file_read), Toast.LENGTH_SHORT).show()
                llUploadPreview.visibility = View.GONE
                return@launch
            }

            val uploadRes = apiClient.uploadChatFile(sUrl, token, prepared.file, prepared.mimeType)
            prepared.cleanup()
            uploadRes.onSuccess { res ->
                pendingUpload = res
                tvUploadFilename.text = res.fileName ?: prepared.file.name
            }.onFailure {
                Toast.makeText(this@ChatActivity, getString(R.string.err_upload, it.message), Toast.LENGTH_LONG).show()
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
                Toast.makeText(this@ChatActivity, getString(R.string.err_group_details), Toast.LENGTH_SHORT).show()
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
            binding.tvGroupInfoTitle.text = group.title ?: getString(R.string.group_chat)
            binding.tvGroupInfoSubtitle.text = getString(R.string.group_members_online_info, group.memberCount, group.onlineCount)
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
                            Toast.makeText(this@ChatActivity, getString(R.string.err_role_change, it.message), Toast.LENGTH_SHORT).show()
                        }
                    }
                },
                onRemoveMember = { participant ->
                    AlertDialog.Builder(this@ChatActivity)
                        .setTitle(getString(R.string.remove_member_title))
                        .setMessage(getString(R.string.remove_member_confirm, participant.name ?: participant.extension))
                        .setPositiveButton(getString(R.string.btn_remove)) { _, _ ->
                            lifecycleScope.launch {
                                val remRes = apiClient.removeGroupMember(sUrl, token, convId, participant.extension)
                                remRes.onSuccess {
                                    dialog.dismiss()
                                    showGroupInfoDialog()
                                    loadGroupDetails()
                                }.onFailure {
                                    Toast.makeText(this@ChatActivity, getString(R.string.err_member_remove, it.message), Toast.LENGTH_SHORT).show()
                                }
                            }
                        }
                        .setNegativeButton(getString(R.string.btn_cancel), null)
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
                    .setTitle(getString(R.string.ui_leave_group))
                    .setMessage(getString(R.string.leave_group_confirm))
                    .setPositiveButton(getString(R.string.btn_leave)) { _, _ ->
                        lifecycleScope.launch {
                            val leaveRes = apiClient.leaveGroup(sUrl, token, convId)
                            leaveRes.onSuccess {
                                Toast.makeText(this@ChatActivity, getString(R.string.msg_left_group), Toast.LENGTH_SHORT).show()
                                dialog.dismiss()
                                finish()
                            }.onFailure {
                                Toast.makeText(this@ChatActivity, getString(R.string.err_action_failed, it.message), Toast.LENGTH_LONG).show()
                            }
                        }
                    }
                    .setNegativeButton(getString(R.string.btn_dismiss), null)
                    .show()
            }

            // Delete Group
            binding.btnDeleteGroup.setOnClickListener {
                AlertDialog.Builder(this@ChatActivity)
                    .setTitle(getString(R.string.ui_delete_group))
                    .setMessage(getString(R.string.delete_group_confirm))
                    .setPositiveButton(getString(R.string.ui_delete)) { _, _ ->
                        lifecycleScope.launch {
                            val delRes = apiClient.deleteGroup(sUrl, token, convId)
                            delRes.onSuccess {
                                Toast.makeText(this@ChatActivity, getString(R.string.msg_group_deleted), Toast.LENGTH_SHORT).show()
                                dialog.dismiss()
                                finish()
                            }.onFailure {
                                Toast.makeText(this@ChatActivity, getString(R.string.err_delete, it.message), Toast.LENGTH_LONG).show()
                            }
                        }
                    }
                    .setNegativeButton(getString(R.string.btn_dismiss), null)
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
        btnSubmit.text = getString(R.string.features_btn_save)

        etTitle.setText(group.title ?: "")
        etDesc.setText(group.description ?: "")

        val dialog = AlertDialog.Builder(this)
            .setTitle(getString(R.string.edit_group_title))
            .setView(view)
            .create()

        btnCancel.setOnClickListener { dialog.dismiss() }

        btnSubmit.setOnClickListener {
            val newTitle = etTitle.text.toString().trim()
            val newDesc = etDesc.text.toString().trim()
            if (newTitle.isEmpty()) {
                Toast.makeText(this, getString(R.string.err_group_name_required), Toast.LENGTH_SHORT).show()
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
                    Toast.makeText(this@ChatActivity, getString(R.string.err_update_it, it.message), Toast.LENGTH_SHORT).show()
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
                Toast.makeText(this@ChatActivity, getString(R.string.no_new_extensions), Toast.LENGTH_SHORT).show()
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
            btnSubmit.text = getString(R.string.btn_add_members)

            val selectionAdapter = ContactSelectionAdapter { selected ->
                tvSelected.text = getString(R.string.select_members_count, selected.size)
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
                .setTitle(getString(R.string.add_members_title))
                .setView(view)
                .create()

            btnCancel.setOnClickListener { dialog.dismiss() }

            btnSubmit.setOnClickListener {
                val selected = selectionAdapter.getSelectedExtensions().toList()
                if (selected.isEmpty()) {
                    Toast.makeText(this@ChatActivity, getString(R.string.err_select_member), Toast.LENGTH_SHORT).show()
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
                        Toast.makeText(this@ChatActivity, getString(R.string.err_members_add, it.message), Toast.LENGTH_SHORT).show()
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
                    tvTyping.text = getString(R.string.typing_name, fromName)
                } else {
                    tvTyping.visibility = View.GONE
                }
            }
        }
    }

    private fun lastSeenText(): String {
        val iso = ChatWebSocketManager.instance.getLastSeen(targetExt) ?: return getString(R.string.ui_offline)
        return try {
            val t = java.time.OffsetDateTime.parse(iso).atZoneSameInstant(java.time.ZoneId.systemDefault())
            val hm = t.format(java.time.format.DateTimeFormatter.ofPattern("HH:mm"))
            if (t.toLocalDate() == java.time.LocalDate.now()) getString(R.string.last_seen, hm)
            else getString(R.string.last_seen, t.format(java.time.format.DateTimeFormatter.ofPattern("dd.MM HH:mm")))
        } catch (e: Exception) {
            getString(R.string.ui_offline)
        }
    }

    override fun onReceipts(conversationId: Int, readUpto: Long, deliveredUpto: Long) {
        if (conversationId == convId) {
            runOnUiThread { if (::adapter.isInitialized) adapter.applyReceipts(readUpto, deliveredUpto) }
        }
    }

    private fun applyTargetPresence(isOnline: Boolean) {
        val tvStatus = findViewById<TextView>(R.id.tvTargetStatus)
        val dot = findViewById<View>(R.id.vTargetOnlineDot)
        tvStatus.text = if (isOnline) getString(R.string.status_online) else lastSeenText()
        tvStatus.setTextColor(if (isOnline) 0xFF10B981.toInt() else 0xFF64748B.toInt())
        dot.backgroundTintList = android.content.res.ColorStateList.valueOf(
            if (isOnline) 0xFF10B981.toInt() else 0xFF9CA3AF.toInt()
        )
    }

    override fun onPresence(extension: String, isOnline: Boolean) {
        if (!isGroup && extension == targetExt) {
            runOnUiThread { applyTargetPresence(isOnline) }
        } else if (isGroup) {
            // The snapshot produces a separate event for every extension — refresh
            // the details only if it is a member of this group (or the members are not known yet).
            val members = currentGroupDetails?.participants
            if (members == null || members.any { it.extension == extension }) {
                loadGroupDetails()
            }
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
                    Toast.makeText(this, getString(R.string.msg_removed_from_group), Toast.LENGTH_LONG).show()
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
                Toast.makeText(this, getString(R.string.msg_group_deleted), Toast.LENGTH_LONG).show()
                finish()
            }
        }
    }
}
