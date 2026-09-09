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
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.ApiClient
import com.mhrgl.aipbx.data.AppPreferences
import com.mhrgl.aipbx.data.ChatEventListener
import com.mhrgl.aipbx.data.ChatWebSocketManager
import com.mhrgl.aipbx.databinding.DialogNewChatBinding
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

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_chat_list)

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

        findViewById<ImageButton>(R.id.btnNewChat).setOnClickListener {
            showNewChatDialog()
        }

        findViewById<Button>(R.id.btnEmptyNewChat).setOnClickListener {
            showNewChatDialog()
        }

        etSearch.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {
                filterConversations(s?.toString() ?: "")
            }
            override fun afterTextChanged(s: Editable?) {}
        })
    }

    private fun setupRecyclerView() {
        adapter = ChatConversationAdapter { conv ->
            ChatActivity.start(this, conv.id, conv.targetExt ?: "", conv.targetName)
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

        if (q.isEmpty()) {
            displayList.addAll(allConversations)
        } else {
            // 1. Var olan sohbetlerden eşleşenler
            val matchedConversations = allConversations.filter {
                SearchUtils.matches(it.targetName, q) ||
                SearchUtils.matches(it.targetExt, q) ||
                SearchUtils.matches(it.lastMessageText, q)
            }
            displayList.addAll(matchedConversations)

            // 2. Tüm kurumsal rehberden eşleşenler
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

        adapter.submitList(displayList)
        if (displayList.isEmpty()) {
            llEmptyState.visibility = View.VISIBLE
            rvConversations.visibility = View.GONE
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
                ChatActivity.start(this@ChatListActivity, 0, selected.extension, selected.name)
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

    // --- ChatEventListener Callbacks ---

    override fun onNewMessage(message: ChatMessage) {
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
