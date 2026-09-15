import SwiftUI

public struct ChatMessageBubbleView: View {
    public let message: ChatMessage
    public let isGroup: Bool

    public init(message: ChatMessage, isGroup: Bool = false) {
        self.message = message
        self.isGroup = isGroup
    }

    private var isMe: Bool {
        return message.isMe ?? false
    }

    private var senderColor: Color {
        let colors: [Color] = [.blue, .purple, .pink, .orange, .teal, .green]
        let hash = abs(message.senderName.hashValue)
        return colors[hash % colors.count]
    }

    public var body: some View {
        if message.isSystem {
            // System Notification Bubble
            HStack {
                Spacer()
                Text(message.message ?? "")
                    .font(.caption)
                    .foregroundColor(.secondary)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 4)
                    .background(Color(.systemGray5))
                    .cornerRadius(12)
                Spacer()
            }
            .padding(.vertical, 4)
        } else {
            // Normal Chat Message Bubble
            HStack {
                if isMe { Spacer(minLength: 50) }

                VStack(alignment: isMe ? .trailing : .leading, spacing: 3) {
                    // Sender Name (in groups, if not me)
                    if isGroup && !isMe {
                        Text(message.senderName)
                            .font(.caption2.bold())
                            .foregroundColor(senderColor)
                            .padding(.horizontal, 4)
                    }

                    // Message Content Container
                    VStack(alignment: .leading, spacing: 4) {
                        if let text = message.message, !text.isEmpty {
                            Text(text)
                                .font(.system(size: 15))
                                .foregroundColor(isMe ? .white : .primary)
                        }

                        // Attachment preview if present
                        if let fileName = message.fileName {
                            HStack(spacing: 6) {
                                Image(systemName: "doc.fill")
                                Text(fileName)
                                    .font(.caption)
                                    .lineLimit(1)
                            }
                            .foregroundColor(isMe ? .white.opacity(0.9) : .blue)
                            .padding(.top, 2)
                        }
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 8)
                    .background(isMe ? Color.blue : Color(.systemGray6))
                    .cornerRadius(16)

                    // Timestamp
                    Text(formatTime(message.createdAt))
                        .font(.system(size: 10))
                        .foregroundColor(.secondary)
                        .padding(.horizontal, 4)
                }

                if !isMe { Spacer(minLength: 50) }
            }
            .padding(.vertical, 2)
        }
    }

    private func formatTime(_ rawDate: String) -> String {
        // Formats "2026-09-15 14:30:00" to "14:30"
        let parts = rawDate.split(separator: " ")
        if parts.count >= 2 {
            let timeParts = parts[1].split(separator: ":")
            if timeParts.count >= 2 {
                return "\(timeParts[0]):\(timeParts[1])"
            }
        }
        return rawDate
    }
}
