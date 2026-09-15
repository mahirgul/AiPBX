import Foundation
import Combine

public protocol ChatWebSocketDelegate: AnyObject {
    func webSocketDidUpdateConnectionState(_ isConnected: Bool)
    func webSocketDidReceiveNewMessage(_ message: ChatMessage)
    func webSocketDidReceivePresence(extensionNumber: String, isOnline: Bool)
    func webSocketDidReceiveTyping(conversationId: Int, senderName: String, isTyping: Bool)
}

public final class ChatWebSocketManager: NSObject, ObservableObject {
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
        self.session = URLSession(configuration: config, delegate: nil, delegateQueue: OperationQueue())
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

        AppLogManager.shared.info("ChatWS", "Connecting to WS: \(wsUrl.absoluteString)")
        webSocketTask = session?.webSocketTask(with: wsUrl)
        webSocketTask?.resume()

        listenForMessages()
        schedulePing()

        DispatchQueue.main.async {
            self.isConnected = true
            self.delegate?.webSocketDidUpdateConnectionState(true)
        }
    }

    public func disconnect(manual: Bool = true) {
        isManuallyClosed = manual
        pingTimer?.invalidate()
        pingTimer = nil

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
            "action": "read",
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

        case "presence":
            if let ext = json["extension"] as? String,
               let online = json["is_online"] as? Bool {
                DispatchQueue.main.async {
                    self.delegate?.webSocketDidReceivePresence(extensionNumber: ext, isOnline: online)
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

        default:
            break
        }
    }

    private func schedulePing() {
        pingTimer?.invalidate()
        pingTimer = Timer.scheduledTimer(withTimeInterval: 25.0, repeats: true) { [weak self] _ in
            self?.webSocketTask?.sendPing { error in
                if let error = error {
                    AppLogManager.shared.warn("ChatWS", "Ping failed: \(error.localizedDescription)")
                }
            }
        }
    }

    private func reconnect() {
        reconnectAttempts += 1
        let delay = min(Double(reconnectAttempts * 2), 20.0)
        DispatchQueue.global().asyncAfter(deadline: .now() + delay) { [weak self] in
            guard let self = self, !self.isManuallyClosed else { return }
            self.connect(baseUrl: self.currentBaseUrl, token: self.currentToken)
        }
    }
}
