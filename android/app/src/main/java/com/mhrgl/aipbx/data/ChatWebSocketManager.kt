package com.mhrgl.aipbx.data

import android.util.Log
import com.google.gson.Gson
import com.mhrgl.aipbx.model.ChatMessage
import com.mhrgl.aipbx.model.ChatConversation
import kotlinx.coroutines.*
import okhttp3.*
import org.json.JSONObject
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.TimeUnit

interface ChatEventListener {
    fun onNewMessage(message: ChatMessage) {}
    fun onPresence(extension: String, isOnline: Boolean) {}
    fun onTyping(conversationId: Int, fromName: String, isTyping: Boolean) {}
    fun onMessagesRead(conversationId: Int, readerExt: String, lastMessageId: Long) {}
    /** Own messages up to readUpto are read, up to deliveredUpto delivered (by every other participant). */
    fun onReceipts(conversationId: Int, readUpto: Long, deliveredUpto: Long) {}
    fun onGroupCreated(conversation: ChatConversation) {}
    fun onGroupUpdated(conversationId: Int, title: String?, avatarUrl: String?, description: String?) {}
    fun onGroupMemberAdded(conversationId: Int, members: List<String>, actor: String) {}
    fun onGroupMemberRemoved(conversationId: Int, extension: String, actor: String) {}
    fun onGroupRoleUpdated(conversationId: Int, extension: String, role: String, actor: String) {}
    fun onGroupDeleted(conversationId: Int) {}
    fun onConnectionStateChanged(isConnected: Boolean) {}
}

class ChatWebSocketManager private constructor() {

    private val gson = Gson()
    private val listeners = CopyOnWriteArrayList<ChatEventListener>()
    @Volatile private var webSocket: WebSocket? = null
    @Volatile private var isConnected = false
    /** So a second connect() call during the handshake does not open a new socket. */
    @Volatile private var isConnecting = false
    private var isManuallyClosed = false
    private var reconnectAttempts = 0
    private var currentUrl: String = ""
    private var currentToken: String = ""

    private val onlineExtensions = java.util.concurrent.ConcurrentHashMap.newKeySet<String>()
    /** ext -> ISO time the user was last active (from presence events). */
    private val lastSeen = java.util.concurrent.ConcurrentHashMap<String, String>()

    /**
     * Whether the user is looking at the app. The foreground service keeps the
     * socket open in the background; the server must not show the user online then.
     */
    @Volatile private var appActive = false

    fun setActive(active: Boolean) {
        appActive = active
        webSocket?.send(JSONObject().apply {
            put("action", "set_active")
            put("active", active)
        }.toString())
    }

    fun getLastSeen(ext: String?): String? = if (ext.isNullOrEmpty()) null else lastSeen[ext]

    fun isOnline(ext: String?): Boolean {
        if (ext.isNullOrEmpty()) return false
        return onlineExtensions.contains(ext)
    }

    fun getOnlineExtensions(): Set<String> = onlineExtensions

    private val client: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .pingInterval(25, TimeUnit.SECONDS)
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(0, TimeUnit.MILLISECONDS) // infinite for WS
            .build()
    }

    private val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    companion object {
        private const val TAG = "ChatWSManager"
        val instance: ChatWebSocketManager by lazy { ChatWebSocketManager() }
    }

    fun addListener(listener: ChatEventListener) {
        if (!listeners.contains(listener)) {
            listeners.add(listener)
            listener.onConnectionStateChanged(isConnected)
        }
    }

    fun removeListener(listener: ChatEventListener) {
        listeners.remove(listener)
    }

    fun connect(baseUrl: String, token: String) {
        if (baseUrl.isEmpty() || token.isEmpty()) return

        // ChatListActivity, ChatActivity, DialerActivity and PbxForegroundService
        // all call connect(). A call arriving during the handshake used to cancel
        // the old socket and open a new one: the server counted the same phone as
        // two devices, and the cancelled socket's late onFailure wiped the new
        // connection's online list (everyone looked "Offline").
        if ((isConnected || isConnecting) && webSocket != null && currentUrl == baseUrl && currentToken == token) {
            Log.d(TAG, "Chat WS already connected/connecting with current credentials, skipping redundant connect")
            return
        }

        currentUrl = baseUrl
        currentToken = token
        isManuallyClosed = false

        val wsProto = if (baseUrl.startsWith("https://", ignoreCase = true)) "wss://" else "ws://"
        val cleanHost = baseUrl
            .removePrefix("https://")
            .removePrefix("http://")
            .trimEnd('/')

        val fullWsUrl = "$wsProto$cleanHost/chat/ws?token=$token&active=${if (appActive) 1 else 0}"

        Log.i(TAG, "Connecting to Chat WS: $fullWsUrl")

        val request = Request.Builder()
            .url(fullWsUrl)
            .build()

        val old = webSocket
        isConnected = false
        isConnecting = true
        webSocket = client.newWebSocket(request, createWebSocketListener())
        old?.cancel()
    }

    fun disconnect() {
        isManuallyClosed = true
        webSocket?.close(1000, "Normal closure")
        webSocket = null
        isConnected = false
        isConnecting = false
        onlineExtensions.clear()
        notifyConnectionState(false)
    }

    fun sendMessage(convId: Int, msgType: String, message: String, attachmentUrl: String? = null, fileName: String? = null, fileSize: Long = 0, mimeType: String? = null) {
        val payload = JSONObject().apply {
            put("action", "send_message")
            put("conversation_id", convId)
            put("msg_type", msgType)
            put("message", message)
            if (!attachmentUrl.isNullOrEmpty()) put("attachment_url", attachmentUrl)
            if (!fileName.isNullOrEmpty()) put("file_name", fileName)
            if (fileSize > 0) put("file_size", fileSize)
            if (!mimeType.isNullOrEmpty()) put("mime_type", mimeType)
        }.toString()

        val sent = webSocket?.send(payload) ?: false
        if (!sent) {
            Log.w(TAG, "Failed to send WS message: socket not open")
        }
    }

    fun sendTyping(convId: Int, isTyping: Boolean) {
        val payload = JSONObject().apply {
            put("action", "typing")
            put("conversation_id", convId)
            put("is_typing", isTyping)
        }.toString()
        webSocket?.send(payload)
    }

    fun sendMarkRead(convId: Int, lastMsgId: Long) {
        val payload = JSONObject().apply {
            put("action", "mark_read")
            put("conversation_id", convId)
            put("last_message_id", lastMsgId)
        }.toString()
        webSocket?.send(payload)
    }

    private fun createWebSocketListener(): WebSocketListener {
        return object : WebSocketListener() {
            override fun onOpen(ws: WebSocket, response: Response) {
                if (ws !== webSocket) return
                Log.i(TAG, "Chat WebSocket connected successfully")
                isConnecting = false
                isConnected = true
                reconnectAttempts = 0
                // The state may have changed while the handshake was running.
                setActive(appActive)
                notifyConnectionState(true)
            }

            override fun onMessage(ws: WebSocket, text: String) {
                if (ws !== webSocket) return
                try {
                    val root = JSONObject(text)
                    val event = root.optString("event")

                    when (event) {
                        "new_message" -> {
                            val dataObj = root.optJSONObject("data") ?: return
                            val msg = gson.fromJson(dataObj.toString(), ChatMessage::class.java)
                            if (msg != null) {
                                for (l in listeners) l.onNewMessage(msg)
                            }
                        }
                        "presence" -> {
                            val ext = root.optString("extension")
                            val isOnline = root.optBoolean("is_online", false)
                            if (ext.isNotEmpty()) {
                                if (isOnline) onlineExtensions.add(ext) else onlineExtensions.remove(ext)
                                root.optString("last_seen").takeIf { it.isNotEmpty() }?.let { lastSeen[ext] = it }
                            }
                            for (l in listeners) l.onPresence(ext, isOnline)
                        }
                        "presence_snapshot" -> {
                            // The snapshot is the full list: anyone not on it is offline.
                            val snapshot = mutableSetOf<String>()
                            val arr = root.optJSONArray("extensions")
                            if (arr != null) {
                                for (i in 0 until arr.length()) {
                                    val ext = arr.optString(i)
                                    if (ext.isNotEmpty()) snapshot.add(ext)
                                }
                            }
                            root.optJSONObject("last_seen")?.let { seen ->
                                seen.keys().forEach { k -> seen.optString(k).takeIf { it.isNotEmpty() }?.let { lastSeen[k] = it } }
                            }
                            val wentOffline = onlineExtensions.filter { it !in snapshot }
                            onlineExtensions.retainAll(snapshot)
                            onlineExtensions.addAll(snapshot)
                            for (ext in wentOffline) for (l in listeners) l.onPresence(ext, false)
                            for (ext in snapshot) for (l in listeners) l.onPresence(ext, true)
                        }
                        "typing" -> {
                            val convId = root.optInt("conversation_id")
                            val fromName = root.optString("from_name")
                            val isTyping = root.optBoolean("is_typing", false)
                            for (l in listeners) l.onTyping(convId, fromName, isTyping)
                        }
                        "receipts" -> {
                            val convId = root.optInt("conversation_id")
                            val readUpto = root.optLong("read_upto")
                            val deliveredUpto = root.optLong("delivered_upto")
                            for (l in listeners) l.onReceipts(convId, readUpto, deliveredUpto)
                        }
                        "messages_read" -> {
                            val convId = root.optInt("conversation_id")
                            val readerExt = root.optString("reader_ext")
                            val lastId = root.optLong("last_message_id")
                            for (l in listeners) l.onMessagesRead(convId, readerExt, lastId)
                        }
                        "group_created" -> {
                            val dataObj = root.optJSONObject("data") ?: return
                            val conv = gson.fromJson(dataObj.toString(), ChatConversation::class.java)
                            if (conv != null) {
                                for (l in listeners) l.onGroupCreated(conv)
                            }
                        }
                        "group_updated" -> {
                            val dataObj = root.optJSONObject("data") ?: return
                            val convId = dataObj.optInt("conversation_id")
                            val title = dataObj.optString("title")
                            val avatarUrl = dataObj.optString("avatar_url")
                            val description = dataObj.optString("description")
                            for (l in listeners) l.onGroupUpdated(convId, title, avatarUrl, description)
                        }
                        "group_member_added" -> {
                            val dataObj = root.optJSONObject("data") ?: return
                            val convId = dataObj.optInt("conversation_id")
                            val membersArr = dataObj.optJSONArray("members")
                            val members = mutableListOf<String>()
                            if (membersArr != null) {
                                for (i in 0 until membersArr.length()) members.add(membersArr.getString(i))
                            }
                            val actor = dataObj.optString("actor")
                            for (l in listeners) l.onGroupMemberAdded(convId, members, actor)
                        }
                        "group_member_removed" -> {
                            val dataObj = root.optJSONObject("data") ?: return
                            val convId = dataObj.optInt("conversation_id")
                            val ext = dataObj.optString("extension")
                            val actor = dataObj.optString("actor")
                            for (l in listeners) l.onGroupMemberRemoved(convId, ext, actor)
                        }
                        "group_role_updated" -> {
                            val dataObj = root.optJSONObject("data") ?: return
                            val convId = dataObj.optInt("conversation_id")
                            val ext = dataObj.optString("extension")
                            val role = dataObj.optString("role")
                            val actor = dataObj.optString("actor")
                            for (l in listeners) l.onGroupRoleUpdated(convId, ext, role, actor)
                        }
                        "group_deleted" -> {
                            val dataObj = root.optJSONObject("data") ?: return
                            val convId = dataObj.optInt("conversation_id")
                            for (l in listeners) l.onGroupDeleted(convId)
                        }
                    }
                } catch (e: Exception) {
                    Log.e(TAG, "Error parsing WS incoming frame: $text", e)
                }
            }

            override fun onClosing(ws: WebSocket, code: Int, reason: String) {
                Log.i(TAG, "Chat WebSocket closing: $code / $reason")
            }

            override fun onClosed(ws: WebSocket, code: Int, reason: String) {
                Log.i(TAG, "Chat WebSocket closed: $code / $reason")
                // An old socket replaced (cancelled) by a new one: it must not disturb the state.
                if (ws !== webSocket) return
                isConnecting = false
                isConnected = false
                onlineExtensions.clear()
                notifyConnectionState(false)
                scheduleReconnect()
            }

            override fun onFailure(ws: WebSocket, t: Throwable, response: Response?) {
                Log.w(TAG, "Chat WebSocket failure: ${t.message}")
                if (ws !== webSocket) return
                isConnecting = false
                isConnected = false
                onlineExtensions.clear()
                notifyConnectionState(false)
                scheduleReconnect()
            }
        }
    }

    private fun scheduleReconnect() {
        if (isManuallyClosed) return

        reconnectAttempts++
        val delaySec = (reconnectAttempts * 2).coerceAtMost(30)
        Log.i(TAG, "Scheduling Chat WS reconnect in ${delaySec}s (attempt #$reconnectAttempts)...")

        scope.launch {
            delay(delaySec * 1000L)
            if (!isManuallyClosed && !isConnected && !isConnecting && currentUrl.isNotEmpty() && currentToken.isNotEmpty()) {
                connect(currentUrl, currentToken)
            }
        }
    }

    private fun notifyConnectionState(connected: Boolean) {
        for (l in listeners) {
            l.onConnectionStateChanged(connected)
        }
    }
}
