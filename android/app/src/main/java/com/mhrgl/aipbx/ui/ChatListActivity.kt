package com.mhrgl.aipbx.ui

import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.ImageButton
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import android.content.res.ColorStateList
import androidx.core.content.ContextCompat
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.data.ChatEventListener
import com.mhrgl.aipbx.data.ChatWebSocketManager
import com.mhrgl.aipbx.databinding.DialogNewChatBinding
import com.mhrgl.aipbx.databinding.DialogNewGroupBinding
import android.widget.Toast
import androidx.appcompat.widget.PopupMenu
import com.mhrgl.aipbx.model.ChatConversation
import com.mhrgl.aipbx.model.ChatMessage
import com.mhrgl.aipbx.model.ContactItem
import com.mhrgl.aipbx.util.SearchUtils
import kotlinx.coroutines.launch

class ChatListActivity : AppCompatActivity(), ChatEventListener {

    companion object {
        fun start(context: Context) {
            val intent = Intent(context, ChatListActivity::class.java)
            context.startActivity(intent)
        }
    }

    private lateinit var prefs: AppPreferences
    private lateinit var apiClient: ApiClient
    private lateinit var adapter: ChatConversationAdapter

    private lateinit var rvConversations: RecyclerView
    private lateinit var llEmptyState: LinearLayout
    private lateinit var etSearch: EditText
    private lateinit var tvWsStatus: TextView

    private val allConversations = mutableListOf<ChatConversation>()
    private val allCorporateContacts = mutableListOf<ContactItem>()
    private enum class ChatFilter { ALL, DIRECT, GROUP }
    private var chatFilterMode = ChatFilter.ALL

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_chat_list)

        findViewById<View>(R.id.rootChatList)?.let { root ->
            ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
                val systemBars = insets.getInsets(
                    WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
                )
                view.setPadding(systemBars.left, systemBars.top, systemBars.right, systemBars.bottom)
                insets
            }
        }

        prefs = AppPreferences.getInstance(this)
        apiClient = ApiClient { prefs }

        setupViews()
        setupRecyclerView()

        ChatWebSocketManager.instance.addListener(this)
        val sUrl = prefs.serverUrl
        val token = prefs.token
        if (!sUrl.isNullOrEmpty() && !token.isNullOrEmpty()) {
            ChatWebSocketManager.instance.connect(sUrl, token)
        }
    }

    override fun onResume() {
        super.onResume()
        loadConversations()
    }

    override fun onDestroy() {
        super.onDestroy()
        ChatWebSocketManager.instance.removeListener(this)
    }

    private fun setupViews() {
        rvConversations = findViewById(R.id.rvConversations)
        llEmptyState = findViewById(R.id.llEmptyState)
        etSearch = findViewById(R.id.etSearch)
        tvWsStatus = findViewById(R.id.tvWsStatus)

        findViewById<ImageButton>(R.id.btnBack).setOnClickListener {
            finish()
        }

        findViewById<ImageButton>(R.id.btnNewGroup).setOnClickListener {
            showNewGroupDialog()
        }

        findViewById<ImageButton>(R.id.btnNewChat).setOnClickListener {
            showNewChatDialog()
        }

        findViewById<Button>(R.id.btnEmptyNewChat).setOnClickListener {
            showNewChatDialog()
        }

        findViewById<Button>(R.id.btnEmptyNewGroup).setOnClickListener {
            showNewGroupDialog()
        }

        findViewById<TextView>(R.id.btnFilterAll).setOnClickListener {
            setChatFilter(ChatFilter.ALL)
        }

        findViewById<TextView>(R.id.btnFilterDirect).setOnClickListener {
            setChatFilter(ChatFilter.DIRECT)
        }

        findViewById<TextView>(R.id.btnFilterGroups).setOnClickListener {
            setChatFilter(ChatFilter.GROUP)
        }

        etSearch.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {
                filterConversations(s?.toString() ?: "")
            }
            override fun afterTextChanged(s: Editable?) {}
        })
    }

    private fun setChatFilter(filter: ChatFilter) {
        chatFilterMode = filter
        val colorActiveBg = ColorStateList.valueOf(ContextCompat.getColor(this, R.color.primary))
        val colorInactiveBg = ColorStateList.valueOf(ContextCompat.getColor(this, R.color.card_bg))
        val colorActiveText = ContextCompat.getColor(this, android.R.color.white)
        val colorInactiveText = ContextCompat.getColor(this, R.color.text_secondary)

        val btnAll = findViewById<TextView>(R.id.btnFilterAll)
        val btnDirect = findViewById<TextView>(R.id.btnFilterDirect)
        val btnGroups = findViewById<TextView>(R.id.btnFilterGroups)

        btnAll.backgroundTintList = if (filter == ChatFilter.ALL) colorActiveBg else colorInactiveBg
        btnAll.setTextColor(if (filter == ChatFilter.ALL) colorActiveText else colorInactiveText)

        btnDirect.backgroundTintList = if (filter == ChatFilter.DIRECT) colorActiveBg else colorInactiveBg
        btnDirect.setTextColor(if (filter == ChatFilter.DIRECT) colorActiveText else colorInactiveText)

        btnGroups.backgroundTintList = if (filter == ChatFilter.GROUP) colorActiveBg else colorInactiveBg
        btnGroups.setTextColor(if (filter == ChatFilter.GROUP) colorActiveText else colorInactiveText)

        filterConversations(etSearch.text?.toString() ?: "")
    }

    private fun showNewChatMenu(anchor: View) {
        val popup = PopupMenu(this, anchor)
        popup.menu.add(0, 1, 0, "Bireysel Sohbet")
        popup.menu.add(0, 2, 1, "Yeni Grup")
        popup.setOnMenuItemClickListener { item ->
            when (item.itemId) {
                1 -> {
                    showNewChatDialog()
                    true
                }
                2 -> {
                    showNewGroupDialog()
                    true
                }
                else -> false
            }
        }
        popup.show()
    }

    private fun setupRecyclerView() {
        adapter = ChatConversationAdapter { conv ->
            val isGroup = conv.type == "group"
            val targetExt = if (isGroup) "" else (conv.targetExt ?: "")
            val displayName = if (isGroup) conv.title else conv.targetName
            ChatActivity.start(this, conv.id, targetExt, displayName, isGroup)
        }
        rvConversations.layoutManager = LinearLayoutManager(this)
        rvConversations.adapter = adapter
    }

    private fun loadConversations() {
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl
            if (sUrl.isEmpty()) return@launch
            val token = prefs.token ?: return@launch

            val convRes = apiClient.getChatConversations(sUrl, token)
            convRes.onSuccess { list ->
                allConversations.clear()
                allConversations.addAll(list)
                filterConversations(etSearch.text?.toString() ?: "")
            }

            val contactsRes = apiClient.getContacts(sUrl, token)
            contactsRes.onSuccess { response ->
                allCorporateContacts.clear()
                allCorporateContacts.addAll(response.contacts ?: emptyList())
                filterConversations(etSearch.text?.toString() ?: "")
            }
        }
    }

    /**
     * I-4: Sohbet aramasını birleştir — Hem mevcut sohbetler hem tüm kurumsal rehber aranır.
     * Türkçe karakter duyarlı normalizasyon için SearchUtils kullanılır.
     */
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
            // 1. Var olan sohbetlerden eşleşenler
            val matchedConversations = baseList.filter {
                SearchUtils.matches(it.targetName, q) ||
                SearchUtils.matches(it.targetExt, q) ||
                SearchUtils.matches(it.title, q) ||
                SearchUtils.matches(it.lastMessageText, q)
            }
            displayList.addAll(matchedConversations)

            // 2. Tüm kurumsal rehberden eşleşenler
            if (chatFilterMode != ChatFilter.GROUP) {
                val matchedContacts = allCorporateContacts.filter { contact ->
                    contact.extension != myExt && (
                        SearchUtils.matches(contact.name, q) ||
                        SearchUtils.matches(contact.extension, q) ||
                        SearchUtils.matches(contact.role, q)
                    )
                }

                for (contact in matchedContacts) {
                    // Eğer bu dahili zaten eşleşen aktif sohbetlerde varsa mükerrer ekleme
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
                                lastMessageText = "Kişi • Sohbet başlat (#${contact.extension})",
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

        adapter.submitList(displayList)
        if (displayList.isEmpty()) {
            llEmptyState.visibility = View.VISIBLE
            rvConversations.visibility = View.GONE

            val tvTitle = findViewById<TextView>(R.id.tvEmptyTitle)
            val tvSubtitle = findViewById<TextView>(R.id.tvEmptySubtitle)
            val btnNewChat = findViewById<Button>(R.id.btnEmptyNewChat)
            val btnNewGroup = findViewById<Button>(R.id.btnEmptyNewGroup)

            if (chatFilterMode == ChatFilter.GROUP) {
                tvTitle?.text = "Henüz grup sohbeti yok"
                tvSubtitle?.text = "Yeni bir grup oluşturarak ekibinizle anlık mesajlaşabilirsiniz."
                btnNewChat?.visibility = View.GONE
                btnNewGroup?.visibility = View.VISIBLE
            } else {
                tvTitle?.text = "Henüz bir sohbetiniz yok"
                tvSubtitle?.text = "Rehberden bir çalışma arkadaşınızı seçerek veya yeni grup kurarak anlık mesajlaşmaya başlayabilirsiniz."
                btnNewChat?.visibility = View.VISIBLE
                btnNewGroup?.visibility = View.VISIBLE
            }
        } else {
            llEmptyState.visibility = View.GONE
            rvConversations.visibility = View.VISIBLE
        }
    }

    /**
     * I-4: Aranabilir Yeni Sohbet Seçici — Kurum rehberindeki dahili ve isimler arasında anlık filtreleme.
     */
    private fun showNewChatDialog() {
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl
            if (sUrl.isEmpty()) return@launch
            val token = prefs.token ?: return@launch

            val contacts = if (allCorporateContacts.isNotEmpty()) {
                allCorporateContacts
            } else {
                val res = apiClient.getContacts(sUrl, token)
                val fetched = res.getOrNull()?.contacts ?: emptyList()
                allCorporateContacts.clear()
                allCorporateContacts.addAll(fetched)
                fetched
            }

            val myExt = prefs.extension ?: ""
            val otherContacts = contacts.filter { it.extension != myExt }

            if (otherContacts.isEmpty()) {
                AlertDialog.Builder(this@ChatListActivity)
                    .setTitle("Dahili Rehber")
                    .setMessage("Sistemde mesajlaşılabilecek başka dahili bulunamadı.")
                    .setPositiveButton("Tamam", null)
                    .show()
                return@launch
            }

            val dialogBinding = DialogNewChatBinding.inflate(layoutInflater)
            val dialog = AlertDialog.Builder(this@ChatListActivity)
                .setView(dialogBinding.root)
                .create()

            val pickerAdapter = ChatContactPickerAdapter { selected ->
                dialog.dismiss()
                ChatActivity.start(this@ChatListActivity, 0, selected.extension, selected.name, false)
            }

            dialogBinding.rvNewChatContacts.layoutManager = LinearLayoutManager(this@ChatListActivity)
            dialogBinding.rvNewChatContacts.adapter = pickerAdapter
            pickerAdapter.submitList(otherContacts)

            dialogBinding.etSearchContact.addTextChangedListener(object : TextWatcher {
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {
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
                override fun afterTextChanged(s: Editable?) {}
            })

            dialogBinding.btnCancelNewChat.setOnClickListener {
                dialog.dismiss()
            }

            dialog.show()
        }
    }

    private fun showNewGroupDialog() {
        lifecycleScope.launch {
            val sUrl = prefs.serverUrl
            if (sUrl.isEmpty()) return@launch
            val token = prefs.token ?: return@launch

            val contacts = if (allCorporateContacts.isNotEmpty()) {
                allCorporateContacts
            } else {
                val res = apiClient.getContacts(sUrl, token)
                val fetched = res.getOrNull()?.contacts ?: emptyList()
                allCorporateContacts.clear()
                allCorporateContacts.addAll(fetched)
                fetched
            }

            val myExt = prefs.extension ?: ""
            val otherContacts = contacts.filter { it.extension != myExt }

            if (otherContacts.isEmpty()) {
                AlertDialog.Builder(this@ChatListActivity)
                    .setTitle("Yeni Grup")
                    .setMessage("Gruba eklenebilecek başka dahili bulunamadı.")
                    .setPositiveButton("Tamam", null)
                    .show()
                return@launch
            }

            val dialogBinding = DialogNewGroupBinding.inflate(layoutInflater)
            val dialog = AlertDialog.Builder(this@ChatListActivity)
                .setView(dialogBinding.root)
                .create()

            val selectionAdapter = ContactSelectionAdapter { selected ->
                dialogBinding.tvSelectedCount.text = "Üye Seçin (${selected.size} seçildi):"
            }

            dialogBinding.rvGroupMembers.layoutManager = LinearLayoutManager(this@ChatListActivity)
            dialogBinding.rvGroupMembers.adapter = selectionAdapter
            selectionAdapter.submitList(otherContacts)

            dialogBinding.etSearchMember.addTextChangedListener(object : TextWatcher {
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {
                    selectionAdapter.filter(s?.toString() ?: "")
                }
                override fun afterTextChanged(s: Editable?) {}
            })

            dialogBinding.btnCancelNewGroup.setOnClickListener {
                dialog.dismiss()
            }

            dialogBinding.btnSubmitNewGroup.setOnClickListener {
                val title = dialogBinding.etGroupTitle.text?.toString()?.trim() ?: ""
                val desc = dialogBinding.etGroupDesc.text?.toString()?.trim()
                val selectedMembers = selectionAdapter.getSelectedExtensions().toList()

                if (title.isEmpty()) {
                    Toast.makeText(this@ChatListActivity, "Grup adı zorunludur.", Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }

                if (selectedMembers.isEmpty()) {
                    Toast.makeText(this@ChatListActivity, "En az 1 üye seçmelisiniz.", Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }

                dialogBinding.btnSubmitNewGroup.isEnabled = false
                dialogBinding.btnSubmitNewGroup.text = "Oluşturuluyor..."

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
                        ChatActivity.start(
                            context = this@ChatListActivity,
                            convId = newConv.id,
                            targetExt = "",
                            targetName = newConv.title,
                            isGroup = true
                        )
                    }.onFailure { err ->
                        dialogBinding.btnSubmitNewGroup.isEnabled = true
                        dialogBinding.btnSubmitNewGroup.text = "Grubu Oluştur"
                        Toast.makeText(this@ChatListActivity, "Hata: ${err.message}", Toast.LENGTH_LONG).show()
                    }
                }
            }

            dialog.show()
        }
    }

    // --- ChatEventListener Callbacks ---

    override fun onNewMessage(message: ChatMessage) {
        runOnUiThread {
            loadConversations()
        }
    }

    override fun onGroupCreated(conversation: ChatConversation) {
        runOnUiThread {
            loadConversations()
        }
    }

    override fun onGroupUpdated(conversationId: Int, title: String?, avatarUrl: String?, description: String?) {
        runOnUiThread {
            loadConversations()
        }
    }

    override fun onGroupMemberAdded(conversationId: Int, members: List<String>, actor: String) {
        runOnUiThread {
            loadConversations()
        }
    }

    override fun onGroupMemberRemoved(conversationId: Int, extension: String, actor: String) {
        runOnUiThread {
            loadConversations()
        }
    }

    override fun onGroupDeleted(conversationId: Int) {
        runOnUiThread {
            loadConversations()
        }
    }

    override fun onConnectionStateChanged(isConnected: Boolean) {
        runOnUiThread {
            if (isConnected) {
                tvWsStatus.text = "Bağlandı"
                tvWsStatus.setTextColor(0xFF10B981.toInt())
            } else {
                tvWsStatus.text = "Bağlanıyor..."
                tvWsStatus.setTextColor(0xFFF59E0B.toInt())
            }
        }
    }
}
