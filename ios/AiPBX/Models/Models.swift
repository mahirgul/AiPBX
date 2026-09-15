import Foundation

// MARK: - Server & Auth Models

public struct ServerInfo: Codable {
    public let success: Bool
    public let service: String?
    public let version: String?
    public let siteTitle: String?
    public let brandTitle: String?
    public let brandSub: String?
    public let serverTime: Int64?

    enum CodingKeys: String, CodingKey {
        case success
        case service
        case version
        case siteTitle = "site_title"
        case brandTitle = "brand_title"
        case brandSub = "brand_sub"
        case serverTime = "server_time"
    }
}

public struct PushConfig: Codable {
    public let enabled: Bool?
    public let provider: String?
    public let fcmProjectId: String?
    public let fcmAppId: String?
    public let fcmApiKey: String?
    public let fcmSenderId: String?

    enum CodingKeys: String, CodingKey {
        case enabled
        case provider
        case fcmProjectId = "fcm_project_id"
        case fcmAppId = "fcm_app_id"
        case fcmApiKey = "fcm_api_key"
        case fcmSenderId = "fcm_sender_id"
    }
}

public struct LoginResponse: Codable {
    public let success: Bool
    public let error: String?
    public let token: String?
    public let user: UserProfile?
    public let sip: SipCredentials?
    public let pushConfig: PushConfig?

    enum CodingKeys: String, CodingKey {
        case success
        case error
        case token
        case user
        case sip
        case pushConfig = "push_config"
    }
}

public struct UserProfile: Codable, Equatable {
    public let id: Int
    public let username: String
    public let fullName: String?
    public let extensionNumber: String
    public let role: String?

    public var displayName: String {
        if let fn = fullName, !fn.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            return fn
        }
        return username
    }

    enum CodingKeys: String, CodingKey {
        case id
        case username
        case fullName = "full_name"
        case extensionNumber = "extension"
        case role
    }
}

public struct SipCredentials: Codable {
    public let extensionNumber: String
    public let sipUsername: String
    public let sipPassword: String
    public let domain: String
    public let wsUrl: String
    public let turn: TurnConfig?

    enum CodingKeys: String, CodingKey {
        case extensionNumber = "extension"
        case sipUsername = "sip_username"
        case sipPassword = "sip_password"
        case domain
        case wsUrl = "ws_url"
        case turn
    }
}

public struct TurnConfig: Codable {
    public let username: String?
    public let credential: String?
    public let urls: [String]?
}

// MARK: - Enums

public enum ConnectionStatus: String, Codable {
    case disconnected = "DISCONNECTED"
    case connecting = "CONNECTING"
    case connected = "CONNECTED"

    public var localizedText: String {
        switch self {
        case .disconnected: return "Bağlantı Yok"
        case .connecting: return "Bağlanıyor..."
        case .connected: return "Bağlandı"
        }
    }
}

public enum CallStatus: String, Codable {
    case idle = "IDLE"
    case connecting = "CONNECTING"
    case ringingOutgoing = "RINGING_OUTGOING"
    case ringingIncoming = "RINGING_INCOMING"
    case active = "ACTIVE"
    case onHold = "ON_HOLD"
    case ended = "ENDED"

    public var localizedText: String {
        switch self {
        case .idle: return "Hazır"
        case .connecting: return "Aranıyor..."
        case .ringingOutgoing: return "Çalıyor..."
        case .ringingIncoming: return "Gelen Çağrı..."
        case .active: return "Görüşmede"
        case .onHold: return "Beklemede"
        case .ended: return "Görüşme Bitti"
        }
    }
}

// MARK: - Call History Models

public struct CallHistoryResponse: Codable {
    public let success: Bool
    public let extensionNumber: String?
    public let filter: String?
    public let totalReturned: Int?
    public let stats: CallStats?
    public let calls: [CallRecord]?

    enum CodingKeys: String, CodingKey {
        case success
        case extensionNumber = "extension"
        case filter
        case totalReturned = "total_returned"
        case stats
        case calls
    }
}

public struct CallStats: Codable {
    public let totalCalls: Int?
    public let incomingCalls: Int?
    public let outgoingCalls: Int?
    public let missedCalls: Int?
    public let totalBillsec: Int?

    enum CodingKeys: String, CodingKey {
        case totalCalls = "total_calls"
        case incomingCalls = "incoming_calls"
        case outgoingCalls = "outgoing_calls"
        case missedCalls = "missed_calls"
        case totalBillsec = "total_billsec"
    }
}

public struct CallRecord: Codable, Identifiable, Equatable {
    public let id: Int64
    public let calldate: String
    public let direction: String // "in", "out", "missed"
    public let party: String
    public let partyName: String?
    public let duration: Int
    public let billsec: Int
    public let disposition: String?
    public let channel: String?

    public var formattedDuration: String {
        let minutes = billsec / 60
        let seconds = billsec % 60
        return String(format: "%02d:%02d", minutes, seconds)
    }

    public var effectiveName: String {
        if let name = partyName, !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            return name
        }
        return party
    }

    enum CodingKeys: String, CodingKey {
        case id
        case calldate
        case direction
        case party
        case partyName = "party_name"
        case duration
        case billsec
        case disposition
        case channel
    }
}

// MARK: - Contacts Models

public struct ContactsResponse: Codable {
    public let success: Bool
    public let total: Int?
    public let contacts: [ContactItem]?
}

public struct ContactItem: Codable, Identifiable, Hashable {
    public var id: String { extensionNumber }
    public let extensionNumber: String
    public let name: String
    public let role: String?
    public var status: String // "online", "busy", "offline"
    public let sipStatus: String?
    public let webrtcStatus: String?
    public let mobWebrtcStatus: String?

    public var isOnline: Bool {
        return status.lowercased() == "online" ||
               status.lowercased() == "busy" ||
               (sipStatus?.lowercased() == "ok") ||
               (webrtcStatus?.lowercased() == "ok") ||
               (mobWebrtcStatus?.lowercased() == "ok")
    }

    enum CodingKeys: String, CodingKey {
        case extensionNumber = "extension"
        case name
        case role
        case status
        case sipStatus = "sip_status"
        case webrtcStatus = "webrtc_status"
        case mobWebrtcStatus = "mob_webrtc_status"
    }
}

// MARK: - Features / Settings Models

public struct FeaturesResponse: Codable {
    public let success: Bool
    public let message: String?
    public let features: FeatureSettings?
}

public struct FeatureSettings: Codable {
    public let extensionNumber: String
    public var dndEnabled: Bool
    public var callForwardNumber: String?
    public var cfBusyNumber: String?
    public var cfNoAnswerNumber: String?
    public var cfNoAnswerTimeout: Int?
    public var allowedPhoneMode: String?

    enum CodingKeys: String, CodingKey {
        case extensionNumber = "extension"
        case dndEnabled = "dnd_enabled"
        case callForwardNumber = "call_forward_number"
        case cfBusyNumber = "cf_busy_number"
        case cfNoAnswerNumber = "cf_noanswer_number"
        case cfNoAnswerTimeout = "cf_noanswer_timeout"
        case allowedPhoneMode = "allowed_phone_mode"
    }
}

// MARK: - Chat & Media Models

public struct ChatConversationsResponse: Codable {
    public let success: Bool
    public let conversations: [ChatConversation]?
}

public struct ChatConversation: Codable, Identifiable, Hashable {
    public let id: Int
    public let type: String // "direct" or "group"
    public let directKey: String?
    public var title: String?
    public var avatarUrl: String?
    public var descriptionText: String?
    public let createdBy: String?
    public var lastMessageText: String?
    public var lastMessageAt: String?
    public var unreadCount: Int
    public let targetExt: String?
    public let targetName: String?
    public var targetOnline: Bool?
    public var memberCount: Int?
    public var onlineCount: Int?
    public var myRole: String?
    public var participants: [ChatParticipant]?

    public var isGroup: Bool {
        return type.lowercased() == "group"
    }

    public var displayTitle: String {
        if isGroup {
            return title ?? "Grup #\(id)"
        }
        if let tName = targetName, !tName.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            return tName
        }
        if let tExt = targetExt, !tExt.isEmpty {
            return tExt
        }
        return title ?? "Sohbet"
    }

    enum CodingKeys: String, CodingKey {
        case id
        case type
        case directKey = "direct_key"
        case title
        case avatarUrl = "avatar_url"
        case descriptionText = "description"
        case createdBy = "created_by"
        case lastMessageText = "last_message_text"
        case lastMessageAt = "last_message_at"
        case unreadCount = "unread_count"
        case targetExt = "target_ext"
        case targetName = "target_name"
        case targetOnline = "target_online"
        case memberCount = "member_count"
        case onlineCount = "online_count"
        case myRole = "my_role"
        case participants
    }
}

public struct ChatParticipant: Codable, Identifiable, Hashable {
    public var id: String { "\(conversationId)_\(extensionNumber)" }
    public let conversationId: Int
    public let extensionNumber: String
    public let name: String?
    public var role: String // "admin", "member"
    public let joinedAt: String?
    public var isOnline: Bool?

    public var displayName: String {
        if let n = name, !n.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            return n
        }
        return extensionNumber
    }

    enum CodingKeys: String, CodingKey {
        case conversationId = "conversation_id"
        case extensionNumber = "extension"
        case name
        case role
        case joinedAt = "joined_at"
        case isOnline = "is_online"
    }
}

public struct ChatMessagesResponse: Codable {
    public let success: Bool
    public let messages: [ChatMessage]?
}

public struct ChatMessage: Codable, Identifiable, Hashable {
    public let id: Int64
    public let conversationId: Int
    public let senderExt: String
    public let senderName: String
    public let msgType: String // "text", "image", "file", "system"
    public let message: String?
    public let attachmentUrl: String?
    public let fileName: String?
    public let fileSize: Int64?
    public let mimeType: String?
    public let createdAt: String
    public var isMe: Bool?
    public let systemEvent: String?
    public let systemMeta: String?

    public var isSystem: Bool {
        return msgType.lowercased() == "system"
    }

    enum CodingKeys: String, CodingKey {
        case id
        case conversationId = "conversation_id"
        case senderExt = "sender_ext"
        case senderName = "sender_name"
        case msgType = "msg_type"
        case message
        case attachmentUrl = "attachment_url"
        case fileName = "file_name"
        case fileSize = "file_size"
        case mimeType = "mime_type"
        case createdAt = "created_at"
        case isMe = "is_me"
        case systemEvent = "system_event"
        case systemMeta = "system_meta"
    }
}

public struct ChatUploadResponse: Codable {
    public let success: Bool
    public let msgType: String?
    public let attachmentUrl: String?
    public let thumbUrl: String?
    public let fileName: String?
    public let fileSize: Int64?
    public let mimeType: String?
    public let error: String?

    enum CodingKeys: String, CodingKey {
        case success
        case msgType = "msg_type"
        case attachmentUrl = "attachment_url"
        case thumbUrl = "thumb_url"
        case fileName = "file_name"
        case fileSize = "file_size"
        case mimeType = "mime_type"
        case error
    }
}

public struct GroupChatResponse: Codable {
    public let success: Bool
    public let conversation: ChatConversation?
    public let error: String?
}

public struct DirectChatResponse: Codable {
    public let success: Bool
    public let conversation: ChatConversation?
    public let error: String?
}

public struct GenericActionResponse: Codable {
    public let success: Bool
    public let error: String?
    public let message: String?
}
