package com.mhrgl.aipbx.model

import com.google.gson.annotations.SerializedName

data class ServerInfo(
    @SerializedName("success") val success: Boolean,
    @SerializedName("service") val service: String?,
    @SerializedName("version") val version: String?,
    @SerializedName("site_title") val siteTitle: String?,
    @SerializedName("brand_title") val brandTitle: String?,
    @SerializedName("brand_sub") val brandSub: String?,
    @SerializedName("server_time") val serverTime: Long?
)

data class PushConfig(
    @SerializedName("enabled") val enabled: Boolean = false,
    @SerializedName("provider") val provider: String = "none",
    @SerializedName("fcm_project_id") val fcmProjectId: String? = null,
    @SerializedName("fcm_app_id") val fcmAppId: String? = null,
    @SerializedName("fcm_api_key") val fcmApiKey: String? = null,
    @SerializedName("fcm_sender_id") val fcmSenderId: String? = null
)

data class LoginResponse(
    @SerializedName("success") val success: Boolean,
    @SerializedName("error") val error: String?,
    @SerializedName("token") val token: String?,
    @SerializedName("user") val user: UserProfile?,
    @SerializedName("sip") val sip: SipCredentials?,
    @SerializedName("push_config") val pushConfig: PushConfig? = null
)

data class UserProfile(
    @SerializedName("id") val id: Int,
    @SerializedName("username") val username: String,
    @SerializedName("full_name") val fullName: String?,
    @SerializedName("extension") val extension: String,
    @SerializedName("role") val role: String?
)

data class SipCredentials(
    @SerializedName("extension") val extension: String,
    @SerializedName("sip_username") val sipUsername: String,
    @SerializedName("sip_password") val sipPassword: String,
    @SerializedName("domain") val domain: String,
    @SerializedName("ws_url") val wsUrl: String,
    @SerializedName("turn") val turn: TurnConfig?
)

data class TurnConfig(
    @SerializedName("username") val username: String?,
    @SerializedName("credential") val credential: String?,
    @SerializedName("urls") val urls: List<String>?
)

enum class ConnectionStatus {
    DISCONNECTED,
    CONNECTING,
    CONNECTED
}

enum class CallStatus {
    IDLE,
    CONNECTING,
    RINGING_OUTGOING,
    RINGING_INCOMING,
    ACTIVE,
    ON_HOLD,
    ENDED
}

data class CallHistoryResponse(
    @SerializedName("success") val success: Boolean,
    @SerializedName("extension") val extension: String?,
    @SerializedName("filter") val filter: String?,
    @SerializedName("total_returned") val totalReturned: Int,
    @SerializedName("stats") val stats: CallStats?,
    @SerializedName("calls") val calls: List<CallRecord>?
)

data class CallStats(
    @SerializedName("total_calls") val totalCalls: Int,
    @SerializedName("incoming_calls") val incomingCalls: Int,
    @SerializedName("outgoing_calls") val outgoingCalls: Int,
    @SerializedName("missed_calls") val missedCalls: Int,
    @SerializedName("total_billsec") val totalBillsec: Int
)

data class CallRecord(
    @SerializedName("id") val id: Long,
    @SerializedName("calldate") val calldate: String,
    @SerializedName("direction") val direction: String, // "in", "out", "missed"
    @SerializedName("party") val party: String,
    @SerializedName("party_name") val partyName: String?,
    @SerializedName("duration") val duration: Int,
    @SerializedName("billsec") val billsec: Int,
    @SerializedName("disposition") val disposition: String?,
    @SerializedName("channel") val channel: String?
)

data class ContactsResponse(
    @SerializedName("success") val success: Boolean,
    @SerializedName("total") val total: Int,
    @SerializedName("contacts") val contacts: List<ContactItem>?
)

data class ContactItem(
    @SerializedName("extension") val extension: String,
    @SerializedName("name") val name: String,
    @SerializedName("role") val role: String?,
    @SerializedName("status") val status: String, // "online", "busy", "offline"
    @SerializedName("sip_status") val sipStatus: String?,
    @SerializedName("webrtc_status") val webrtcStatus: String?,
    @SerializedName("mob_webrtc_status") val mobWebrtcStatus: String? = null
)

data class FeaturesResponse(
    @SerializedName("success") val success: Boolean,
    @SerializedName("message") val message: String?,
    @SerializedName("features") val features: FeatureSettings?
)

data class FeatureSettings(
    @SerializedName("extension") val extension: String,
    @SerializedName("dnd_enabled") val dndEnabled: Boolean,
    @SerializedName("call_forward_number") val callForwardNumber: String?,
    @SerializedName("cf_busy_number") val cfBusyNumber: String? = null,
    @SerializedName("cf_noanswer_number") val cfNoAnswerNumber: String? = null,
    @SerializedName("cf_noanswer_timeout") val cfNoAnswerTimeout: Int? = 20,
    @SerializedName("allowed_phone_mode") val allowedPhoneMode: String? = "both"
)

data class FcmTokenResponse(
    @SerializedName("success") val success: Boolean,
    @SerializedName("message") val message: String?,
    @SerializedName("device_id") val deviceId: String?,
    @SerializedName("id") val id: Int?
)

// --- CHAT & MEDIA MODELS ---

data class ChatConversationsResponse(
    @SerializedName("success") val success: Boolean,
    @SerializedName("conversations") val conversations: List<ChatConversation>?
)

data class ChatConversation(
    @SerializedName("id") val id: Int,
    @SerializedName("type") val type: String,
    @SerializedName("direct_key") val directKey: String? = null,
    @SerializedName("title") val title: String? = null,
    @SerializedName("created_by") val createdBy: String,
    @SerializedName("last_message_text") val lastMessageText: String? = null,
    @SerializedName("last_message_at") val lastMessageAt: String? = null,
    @SerializedName("unread_count") val unreadCount: Int = 0,
    @SerializedName("target_ext") val targetExt: String? = null,
    @SerializedName("target_name") val targetName: String? = null,
    @SerializedName("target_online") val targetOnline: Boolean = false
)

data class ChatMessagesResponse(
    @SerializedName("success") val success: Boolean,
    @SerializedName("messages") val messages: List<ChatMessage>?
)

data class ChatMessage(
    @SerializedName("id") val id: Long,
    @SerializedName("conversation_id") val conversationId: Int,
    @SerializedName("sender_ext") val senderExt: String,
    @SerializedName("sender_name") val senderName: String,
    @SerializedName("msg_type") val msgType: String,
    @SerializedName("message") val message: String? = null,
    @SerializedName("attachment_url") val attachmentUrl: String? = null,
    @SerializedName("file_name") val fileName: String? = null,
    @SerializedName("file_size") val fileSize: Long = 0,
    @SerializedName("mime_type") val mimeType: String? = null,
    @SerializedName("created_at") val createdAt: String,
    @SerializedName("is_me") var isMe: Boolean = false
)

data class ChatUploadResponse(
    @SerializedName("success") val success: Boolean,
    @SerializedName("msg_type") val msgType: String? = null,
    @SerializedName("attachment_url") val attachmentUrl: String? = null,
    @SerializedName("thumb_url") val thumbUrl: String? = null,
    @SerializedName("file_name") val fileName: String? = null,
    @SerializedName("file_size") val fileSize: Long = 0,
    @SerializedName("mime_type") val mimeType: String? = null,
    @SerializedName("error") val error: String? = null
)

data class DirectChatResponse(
    @SerializedName("success") val success: Boolean,
    @SerializedName("conversation") val conversation: ChatConversation?
)

