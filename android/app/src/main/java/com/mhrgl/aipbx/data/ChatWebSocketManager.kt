package com.mhrgl.aipbx.data

import android.util.Log
import com.google.gson.Gson
import com.mhrgl.aipbx.model.ChatMessage
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
    fun onConnectionStateChanged(isConnected: Boolean) {}
}

class ChatWebSocketManager private constructor() {

    private val gson = Gson()
    private val listeners = CopyOnWriteArrayList<ChatEventListener>()
    private var webSocket: WebSocket? = null
    private var isConnected = false
    private var isManuallyClosed = false
    private var reconnectAttempts = 0
    private var currentUrl: String = ""
    private var currentToken: String = ""

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

        if (isConnected && webSocket != null && currentUrl == baseUrl && currentToken == token) {
            Log.d(TAG, "Chat WS already connected with current credentials, skipping redundant connect")
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

        val fullWsUrl = "$wsProto$cleanHost/chat/ws?token=$token"

        Log.i(TAG, "Connecting to Chat WS: $fullWsUrl")

        val request = Request.Builder()
            .url(fullWsUrl)
            .build()

        webSocket?.cancel()
        webSocket = client.newWebSocket(request, createWebSocketListener())
    }

    fun disconnect() {
        isManuallyClosed = true
        webSocket?.close(1000, "Normal closure")
        webSocket = null
        isConnected = false
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
                Log.i(TAG, "Chat WebSocket connected successfully")
                isConnected = true
                reconnectAttempts = 0
                notifyConnectionState(true)
            }

            override fun onMessage(ws: WebSocket, text: String) {
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
                            for (l in listeners) l.onPresence(ext, isOnline)
                        }
                        "typing" -> {
                            val convId = root.optInt("conversation_id")
                            val fromName = root.optString("from_name")
                            val isTyping = root.optBoolean("is_typing", false)
                            for (l in listeners) l.onTyping(convId, fromName, isTyping)
                        }
                        "messages_read" -> {
                            val convId = root.optInt("conversation_id")
                            val readerExt = root.optString("reader_ext")
                            val lastId = root.optLong("last_message_id")
                            for (l in listeners) l.onMessagesRead(convId, readerExt, lastId)
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
                isConnected = false
                notifyConnectionState(false)
                scheduleReconnect()
            }

            override fun onFailure(ws: WebSocket, t: Throwable, response: Response?) {
                Log.w(TAG, "Chat WebSocket failure: ${t.message}")
                isConnected = false
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
            if (!isManuallyClosed && !isConnected && currentUrl.isNotEmpty() && currentToken.isNotEmpty()) {
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
