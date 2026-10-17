import Foundation
import Combine

public protocol ChatWebSocketDelegate: AnyObject {
    func webSocketDidUpdateConnectionState(_ isConnected: Bool)
    func webSocketDidReceiveNewMessage(_ message: ChatMessage)
    func webSocketDidReceiveMessageDeleted(conversationId: Int, messageId: Int64)
    func webSocketDidReceivePresence(extensionNumber: String, isOnline: Bool)
    func webSocketDidReceiveTyping(conversationId: Int, senderName: String, isTyping: Bool)
    func webSocketDidReceiveVoicemail(newCount: Int)
}

public final class ChatWebSocketManager: NSObject, ObservableObject, URLSessionWebSocketDelegate {
    public static let shared = ChatWebSocketManager()

    @Published public private(set) var isConnected: Bool = false
    public weak var delegate: ChatWebSocketDelegate?

    private var webSocketTask: URLSessionWebSocketTask?
    private var session: URLSession?
    private var currentBaseUrl: String = ""
    private var currentToken: String = ""
    private var pingTimer: Timer?
    private var isManuallyClosed: Bool = false
    private var reconnectAttempts: Int = 0

    private override init() {
        super.init()
        let config = URLSessionConfiguration.default
        self.session = URLSession(configuration: config, delegate: self, delegateQueue: OperationQueue())
    }

    public func connect(baseUrl: String, token: String) {
        guard !baseUrl.isEmpty, !token.isEmpty else { return }

        if isConnected && currentBaseUrl == baseUrl && currentToken == token {
            return
        }

        self.currentBaseUrl = baseUrl
        self.currentToken = token
        self.isManuallyClosed = false

        disconnect(manual: false)

        var wsProto = "wss://"
        var cleanHost = baseUrl.trimmingCharacters(in: .whitespacesAndNewlines)
        if cleanHost.hasPrefix("http://") {
            wsProto = "ws://"
            cleanHost = String(cleanHost.dropFirst("http://".count))
        } else if cleanHost.hasPrefix("https://") {
            wsProto = "wss://"
            cleanHost = String(cleanHost.dropFirst("https://".count))
        }
        cleanHost = cleanHost.trimmingCharacters(in: CharacterSet(charactersIn: "/"))

        guard let wsUrl = URL(string: "\(wsProto)\(cleanHost)/chat/ws?token=\(token)") else {
            AppLogManager.shared.error("ChatWS", "Invalid WS URL")
            return
        }

        let maskedUrl = wsUrl.absoluteString.replacingOccurrences(of: "token=[^&]+", with: "token=REDACTED", options: .regularExpression)
        AppLogManager.shared.info("ChatWS", "Connecting to WS: \(maskedUrl)")
        webSocketTask = session?.webSocketTask(with: wsUrl)
        webSocketTask?.resume()

        listenForMessages()
        schedulePing()
    }

    public func disconnect(manual: Bool = true) {
        isManuallyClosed = manual
        DispatchQueue.main.async {
            self.pingTimer?.invalidate()
            self.pingTimer = nil
        }

        webSocketTask?.cancel(with: .normalClosure, reason: nil)
        webSocketTask = nil

        DispatchQueue.main.async {
            self.isConnected = false
            self.delegate?.webSocketDidUpdateConnectionState(false)
        }
        AppLogManager.shared.info("ChatWS", "WebSocket disconnected")
    }

    public func sendMessage(conversationId: Int, message: String) {
        guard isConnected else { return }
        let payload: [String: Any] = [
            "action": "send_message",
            "conversation_id": conversationId,
            "message": message,
            "msg_type": "text"
        ]
        sendJson(payload)
    }

    public func sendAttachment(
        conversationId: Int,
        msgType: String,
        message: String,
        attachmentUrl: String,
        fileName: String,
        fileSize: Int64,
        mimeType: String
    ) {
        guard isConnected else { return }
        let payload: [String: Any] = [
            "action": "send_message",
            "conversation_id": conversationId,
            "msg_type": msgType,
            "message": message,
            "attachment_url": attachmentUrl,
            "file_name": fileName,
            "file_size": fileSize,
            "mime_type": mimeType
        ]
        sendJson(payload)
    }

    public func sendTyping(conversationId: Int, isTyping: Bool) {
        guard isConnected else { return }
        let payload: [String: Any] = [
            "action": "typing",
            "conversation_id": conversationId,
            "is_typing": isTyping
        ]
        sendJson(payload)
    }

    public func markAsRead(conversationId: Int, lastMessageId: Int64) {
        guard isConnected else { return }
        let payload: [String: Any] = [
            // The server only knows "mark_read" (chat/hub.go); "read" was ignored.
            "action": "mark_read",
            "conversation_id": conversationId,
            "last_message_id": lastMessageId
        ]
        sendJson(payload)
    }

    private func sendJson(_ dict: [String: Any]) {
        guard let data = try? JSONSerialization.data(withJSONObject: dict),
              let jsonString = String(data: data, encoding: .utf8) else {
            return
        }
        let message = URLSessionWebSocketTask.Message.string(jsonString)
        webSocketTask?.send(message) { error in
            if let error = error {
                AppLogManager.shared.error("ChatWS", "Error sending message: \(error.localizedDescription)")
            }
        }
    }

    private func listenForMessages() {
        webSocketTask?.receive { [weak self] result in
            guard let self = self else { return }
            switch result {
            case .success(let message):
                self.reconnectAttempts = 0
                DispatchQueue.main.async {
                    if !self.isConnected {
                        self.isConnected = true
                        self.delegate?.webSocketDidUpdateConnectionState(true)
                    }
                }
                switch message {
                case .string(let text):
                    self.handleIncomingText(text)
                case .data(let data):
                    if let text = String(data: data, encoding: .utf8) {
                        self.handleIncomingText(text)
                    }
                @unknown default:
                    break
                }
                self.listenForMessages()

            case .failure(let error):
                AppLogManager.shared.warn("ChatWS", "Receive error: \(error.localizedDescription)")
                DispatchQueue.main.async {
                    self.isConnected = false
                    self.delegate?.webSocketDidUpdateConnectionState(false)
                }
                if !self.isManuallyClosed {
                    self.reconnect()
                }
            }
        }
    }

    public func urlSession(_ session: URLSession, webSocketTask: URLSessionWebSocketTask, didOpenWithProtocol protocol: String?) {
        guard webSocketTask === self.webSocketTask else { return }
        reconnectAttempts = 0
        schedulePing()
        DispatchQueue.main.async {
            self.isConnected = true
            self.delegate?.webSocketDidUpdateConnectionState(true)
        }
    }

    public func urlSession(_ session: URLSession, webSocketTask: URLSessionWebSocketTask, didCloseWith closeCode: URLSessionWebSocketTask.CloseCode, reason: Data?) {
        guard webSocketTask === self.webSocketTask else { return }
        DispatchQueue.main.async {
            self.isConnected = false
            self.delegate?.webSocketDidUpdateConnectionState(false)
        }
    }

    private func handleIncomingText(_ text: String) {
        guard let data = text.data(using: .utf8),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let event = json["event"] as? String else {
            return
        }

        switch event {
        case "new_message":
            if let msgData = try? JSONSerialization.data(withJSONObject: json["data"] ?? [:]),
               let msg = try? JSONDecoder().decode(ChatMessage.self, from: msgData) {
                DispatchQueue.main.async {
                    self.delegate?.webSocketDidReceiveNewMessage(msg)
                }
            }

        case "message_deleted":
            // {"conversation_id", "message_id", "deleted_at"}: the sender
            // deleted a message (on any of their devices).
            if let d = json["data"] as? [String: Any],
               let convId = (d["conversation_id"] as? NSNumber)?.intValue,
               let msgId = (d["message_id"] as? NSNumber)?.int64Value {
                DispatchQueue.main.async {
                    self.delegate?.webSocketDidReceiveMessageDeleted(conversationId: convId, messageId: msgId)
                }
            }

        case "presence":
            if let ext = json["extension"] as? String,
               let online = json["is_online"] as? Bool {
                DispatchQueue.main.async {
                    self.delegate?.webSocketDidReceivePresence(extensionNumber: ext, isOnline: online)
                }
            }

        case "presence_snapshot":
            // On connect the server sends the extensions online at that moment
            // in one list; since it was not handled, people already connected
            // looked offline until they reconnected (the same bug as on Android).
            if let exts = json["extensions"] as? [String] {
                DispatchQueue.main.async {
                    for ext in exts where !ext.isEmpty {
                        self.delegate?.webSocketDidReceivePresence(extensionNumber: ext, isOnline: true)
                    }
                }
            }

        case "typing":
            if let convId = json["conversation_id"] as? Int,
               let name = json["from_name"] as? String,
               let typing = json["is_typing"] as? Bool {
                DispatchQueue.main.async {
                    self.delegate?.webSocketDidReceiveTyping(conversationId: convId, senderName: name, isTyping: typing)
                }
            }

        case "voicemail":
            let d = (json["data"] as? [String: Any]) ?? json
            if let newCount = (d["new_count"] as? NSNumber)?.intValue {
                DispatchQueue.main.async {
                    self.delegate?.webSocketDidReceiveVoicemail(newCount: newCount)
                }
            }

        default:
            break
        }
    }

    private func schedulePing() {
        DispatchQueue.main.async { [weak self] in
            guard let self = self else { return }
            self.pingTimer?.invalidate()
            let timer = Timer(timeInterval: 25.0, repeats: true) { [weak self] _ in
                self?.webSocketTask?.sendPing { error in
                    if let error = error {
                        AppLogManager.shared.warn("ChatWS", "Ping failed: \(error.localizedDescription)")
                    }
                }
            }
            RunLoop.main.add(timer, forMode: .common)
            self.pingTimer = timer
        }
    }

    private func reconnect() {
        reconnectAttempts += 1
        let delay = min(Double(reconnectAttempts * 2), 20.0)
        DispatchQueue.main.asyncAfter(deadline: .now() + delay) { [weak self] in
            guard let self = self, !self.isManuallyClosed else { return }
            self.connect(baseUrl: self.currentBaseUrl, token: self.currentToken)
        }
    }
}
