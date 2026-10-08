import Foundation
import UserNotifications

/// Chat message notifications posted from the live chat connection. Each one
/// carries a "Reply" text field: the answer is sent over the chat REST API
/// without opening the app (the same as Android's ChatNotifications).
public final class ChatNotifications {
    public static let shared = ChatNotifications()

    public static let categoryId = "CHAT_MESSAGE"
    public static let replyActionId = "CHAT_REPLY"

    private static let keyConversationId = "conversation_id"
    private static let keyTitle = "chat_title"

    private init() {}

    /// Registers the notification category with the Reply action. Called at
    /// launch and again when the app language changes (the titles are localized).
    @MainActor
    public func registerCategory() {
        let reply = UNTextInputNotificationAction(
            identifier: Self.replyActionId,
            title: L("Reply"),
            options: [],
            textInputButtonTitle: L("Reply"),
            textInputPlaceholder: L("Message")
        )
        let category = UNNotificationCategory(
            identifier: Self.categoryId,
            actions: [reply],
            intentIdentifiers: [],
            options: []
        )
        UNUserNotificationCenter.current().setNotificationCategories([category])
    }

    private func requestId(_ convId: Int) -> String { "chat_\(convId)" }

    @MainActor
    public func show(message: ChatMessage, conversation: ChatConversation?) {
        let isGroup = conversation?.isGroup ?? false
        let title = isGroup ? (conversation?.displayTitle ?? message.senderName) : message.senderName

        var preview = message.message ?? ""
        if message.msgType == "image" {
            preview = "📷"
        } else if message.msgType == "file" {
            preview = "📎 " + (message.fileName ?? "")
        }
        let body = isGroup ? "\(message.senderName): \(preview)" : preview

        post(convId: message.conversationId, title: title, body: body, silent: false)
    }

    @MainActor
    private func post(convId: Int, title: String, body: String, silent: Bool) {
        let content = UNMutableNotificationContent()
        content.title = title
        content.body = body
        content.sound = silent ? nil : .default
        content.categoryIdentifier = Self.categoryId
        content.threadIdentifier = "conv_\(convId)"
        content.userInfo = [Self.keyConversationId: convId, Self.keyTitle: title]

        let request = UNNotificationRequest(identifier: requestId(convId), content: content, trigger: nil)
        UNUserNotificationCenter.current().add(request) { error in
            if let error = error {
                AppLogManager.shared.warn("ChatNotifications", "Could not post notification: \(error.localizedDescription)")
            }
        }
    }

    /// Sends the text typed into a notification's Reply field, then marks the
    /// conversation read. On failure the notification comes back with the reason.
    @MainActor
    public func handleReply(_ response: UNTextInputNotificationResponse) async {
        let info = response.notification.request.content.userInfo
        let text = response.userText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let convId = (info[Self.keyConversationId] as? NSNumber)?.intValue, convId > 0, !text.isEmpty else {
            return
        }
        let title = info[Self.keyTitle] as? String ?? response.notification.request.content.title

        let appState = AppState.shared
        // iOS may have launched the app just for this Reply: sign back in first.
        await appState.restoreSessionIfNeeded()
        guard let token = appState.token, !token.isEmpty else {
            post(convId: convId, title: title, body: L("Not sent: %@", L("AiPBX session ended")), silent: true)
            return
        }
        do {
            let msgId = try await ApiClient.shared.sendChatText(baseUrl: appState.baseUrl, token: token, convId: convId, text: text)
            if msgId > 0 {
                try? await ApiClient.shared.markChatRead(baseUrl: appState.baseUrl, token: token, convId: convId, lastMessageId: msgId)
            }
            if let idx = appState.conversations.firstIndex(where: { $0.id == convId }) {
                appState.conversations[idx].unreadCount = 0
            }
            UNUserNotificationCenter.current().removeDeliveredNotifications(withIdentifiers: [requestId(convId)])
        } catch {
            AppLogManager.shared.warn("ChatNotifications", "Notification reply failed for conversation \(convId): \(error.localizedDescription)")
            post(convId: convId, title: title, body: L("Not sent: %@", error.localizedDescription), silent: true)
        }
    }
}
