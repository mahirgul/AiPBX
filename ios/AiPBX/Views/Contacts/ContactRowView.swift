import SwiftUI

public struct ContactRowView: View {
    public let contact: ContactItem
    public let onCall: () -> Void
    public let onChat: () -> Void

    public init(contact: ContactItem, onCall: @escaping () -> Void, onChat: @escaping () -> Void) {
        self.contact = contact
        self.onCall = onCall
        self.onChat = onChat
    }

    public var body: some View {
        HStack(spacing: 12) {
            // Avatar with Presence indicator
            ZStack(alignment: .bottomTrailing) {
                AvatarView(name: contact.name, size: 44)

                Circle()
                    .fill(contact.isOnline ? Color.green : Color.gray)
                    .frame(width: 12, height: 12)
                    .overlay(Circle().stroke(Color(.systemBackground), lineWidth: 2))
                    .offset(x: 2, y: 2)
            }

            // Name & Extension & Role
            VStack(alignment: .leading, spacing: 4) {
                HStack(spacing: 6) {
                    Text(contact.name)
                        .font(.system(size: 16, weight: .semibold))
                        .foregroundColor(.primary)

                    if let role = contact.role, !role.isEmpty {
                        RoleBadgeView(role: role)
                    }
                }

                HStack(spacing: 6) {
                    Text("Dahili: \(contact.extensionNumber)")
                        .font(.caption)
                        .foregroundColor(.secondary)

                    Text("•")
                        .font(.caption2)
                        .foregroundColor(.secondary)

                    Text(contact.isOnline ? "Çevrimiçi" : "Çevrimdışı")
                        .font(.caption)
                        .foregroundColor(contact.isOnline ? .green : .secondary)
                }
            }

            Spacer()

            // Call & Chat Actions
            HStack(spacing: 12) {
                Button(action: onChat) {
                    Image(systemName: "bubble.left.fill")
                        .font(.system(size: 18))
                        .foregroundColor(.blue)
                        .padding(8)
                        .background(Color.blue.opacity(0.12))
                        .clipShape(Circle())
                }
                .buttonStyle(BorderlessButtonStyle())

                Button(action: onCall) {
                    Image(systemName: "phone.fill")
                        .font(.system(size: 18))
                        .foregroundColor(.green)
                        .padding(8)
                        .background(Color.green.opacity(0.12))
                        .clipShape(Circle())
                }
                .buttonStyle(BorderlessButtonStyle())
            }
        }
        .padding(.vertical, 4)
    }
}
