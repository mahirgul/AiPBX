import SwiftUI

public struct StatusBadgeView: View {
    public let isOnline: Bool
    public var showText: Bool = false

    public init(isOnline: Bool, showText: Bool = false) {
        self.isOnline = isOnline
        self.showText = showText
    }

    public var body: some View {
        HStack(spacing: 4) {
            Circle()
                .fill(isOnline ? Color.green : Color.gray.opacity(0.6))
                .frame(width: 8, height: 8)

            if showText {
                Text(isOnline ? L("Online") : L("Offline"))
                    .font(.caption2)
                    .foregroundColor(isOnline ? .green : .secondary)
            }
        }
    }
}

public struct RoleBadgeView: View {
    public let role: String
    /// Display name from the server (role_name); the key is mapped when it is missing.
    public let name: String?

    public init(role: String, name: String? = nil) {
        self.role = role
        self.name = name
    }

    private var displayRole: String {
        if let name = name, !name.isEmpty { return name }
        switch role.lowercased() {
        case "admin": return L("Admin")
        case "cc_agent": return L("Agent")
        case "standard_user": return L("Standard")
        case "user": return L("User")
        default: return role.capitalized
        }
    }

    private var badgeColor: Color {
        switch role.lowercased() {
        case "admin": return .red
        case "cc_agent": return .purple
        default: return .blue
        }
    }

    public var body: some View {
        Text(displayRole)
            .font(.system(size: 10, weight: .semibold))
            .padding(.horizontal, 6)
            .padding(.vertical, 2)
            .background(badgeColor.opacity(0.15))
            .foregroundColor(badgeColor)
            .cornerRadius(4)
    }
}
