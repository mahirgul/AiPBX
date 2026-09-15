import SwiftUI

public struct ChatConversationRowView: View {
    public let conversation: ChatConversation

    public init(conversation: ChatConversation) {
        self.conversation = conversation
    }

    public var body: some View {
        HStack(spacing: 12) {
            // Avatar
            AvatarView(name: conversation.displayTitle, size: 48, isGroup: conversation.isGroup)

            // Conversation Info
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text(conversation.displayTitle)
                        .font(.system(size: 16, weight: .semibold))
                        .foregroundColor(.primary)
                        .lineLimit(1)

                    if conversation.isGroup {
                        Text("Grup")
                            .font(.system(size: 10, weight: .bold))
                            .padding(.horizontal, 6)
                            .padding(.vertical, 2)
                            .background(Color.purple.opacity(0.15))
                            .foregroundColor(.purple)
                            .cornerRadius(4)
                    }

                    Spacer()

                    if let timeStr = conversation.lastMessageAt {
                        Text(formatDate(timeStr))
                            .font(.caption2)
                            .foregroundColor(.secondary)
                    }
                }

                HStack {
                    Text(conversation.lastMessageText ?? "Henüz mesaj yok")
                        .font(.subheadline)
                        .foregroundColor(conversation.unreadCount > 0 ? .primary : .secondary)
                        .fontWeight(conversation.unreadCount > 0 ? .medium : .regular)
                        .lineLimit(1)

                    Spacer()

                    if conversation.unreadCount > 0 {
                        Text("\(conversation.unreadCount)")
                            .font(.system(size: 11, weight: .bold))
                            .foregroundColor(.white)
                            .padding(.horizontal, 6)
                            .padding(.vertical, 2)
                            .background(Color.blue)
                            .clipShape(Capsule())
                    }
                }
            }
        }
        .padding(.vertical, 4)
    }

    private func formatDate(_ raw: String) -> String {
        let parts = raw.split(separator: " ")
        if parts.count >= 2 {
            let timeParts = parts[1].split(separator: ":")
            if timeParts.count >= 2 {
                return "\(timeParts[0]):\(timeParts[1])"
            }
        }
        return raw
    }
}
