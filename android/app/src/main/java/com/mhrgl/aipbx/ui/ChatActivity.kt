package com.mhrgl.aipbx.ui

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.widget.*
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.data.ChatEventListener
import com.mhrgl.aipbx.data.ChatWebSocketManager
import com.mhrgl.aipbx.model.ChatMessage
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

        @Volatile
        var activeConversationId: Int = 0

        fun start(context: Context, convId: Int, targetExt: String, targetName: String? = null) {
            val intent = Intent(context, ChatActivity::class.java).apply {
                putExtra(EXTRA_CONV_ID, convId)
                putExtra(EXTRA_TARGET_EXT, targetExt)
                putExtra(EXTRA_TARGET_NAME, targetName)
            }
            context.startActivity(intent)
        }
    }

    private var convId: Int = 0
    private var targetExt: String = ""
    private var targetName: String = ""

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
        val btnCall = findViewById<ImageButton>(R.id.btnCall)

        tvName.text = if (targetName.isNotEmpty()) "$targetName (#$targetExt)" else "Dahili #$targetExt"
        tvAvatar.text = targetName.take(1).uppercase()

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

    private fun setupRecyclerView() {
        val myExt = prefs.extension ?: ""
        val baseUrl = prefs.serverUrl ?: ""

        adapter = ChatMessageAdapter(myExt, baseUrl)
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

            if (convId <= 0 && targetExt.isNotEmpty()) {
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
        if (extension == targetExt) {
            runOnUiThread {
                val tvStatus = findViewById<TextView>(R.id.tvTargetStatus)
                val dot = findViewById<View>(R.id.vTargetOnlineDot)
                tvStatus.text = if (isOnline) "Çevrimiçi" else "Çevrimdışı"
                tvStatus.setTextColor(if (isOnline) 0xFF10B981.toInt() else 0xFF64748B.toInt())
                dot.backgroundTintList = android.content.res.ColorStateList.valueOf(
                    if (isOnline) 0xFF10B981.toInt() else 0xFF9CA3AF.toInt()
                )
            }
        }
    }
}
