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
                Text(isOnline ? "Çevrimiçi" : "Çevrimdışı")
                    .font(.caption2)
                    .foregroundColor(isOnline ? .green : .secondary)
            }
        }
    }
}

public struct RoleBadgeView: View {
    public let role: String

    public init(role: String) {
        self.role = role
    }

    private var displayRole: String {
        switch role.lowercased() {
        case "admin": return "Yönetici"
        case "cc_agent": return "Temsilci"
        case "standard_user": return "Standart"
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
