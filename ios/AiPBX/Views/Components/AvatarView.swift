import SwiftUI

public struct AvatarView: View {
    public let name: String
    public let size: CGFloat
    public var isGroup: Bool = false

    private static let palette: [Color] = [
        Color(red: 0.18, green: 0.53, blue: 0.93), // Blue
        Color(red: 0.08, green: 0.72, blue: 0.65), // Teal
        Color(red: 0.92, green: 0.35, blue: 0.40), // Red
        Color(red: 0.58, green: 0.35, blue: 0.88), // Purple
        Color(red: 0.95, green: 0.60, blue: 0.20), // Orange
        Color(red: 0.20, green: 0.68, blue: 0.35)  // Green
    ]

    public init(name: String, size: CGFloat = 40, isGroup: Bool = false) {
        self.name = name
        self.size = size
        self.isGroup = isGroup
    }

    private var initials: String {
        if isGroup { return "👥" }
        let clean = name.trimmingCharacters(in: .whitespacesAndNewlines)
        if clean.isEmpty { return "?" }
        let parts = clean.split(separator: " ")
        if parts.count >= 2, let first = parts[0].first, let second = parts[1].first {
            return "\(first)\(second)".uppercased()
        }
        return String(clean.prefix(1)).uppercased()
    }

    private var backgroundColor: Color {
        if isGroup {
            return Color.indigo
        }
        let hash = abs(name.hashValue)
        return Self.palette[hash % Self.palette.count]
    }

    public var body: some View {
        ZStack {
            Circle()
                .fill(backgroundColor.gradient)
                .frame(width: size, height: size)

            Text(initials)
                .font(.system(size: size * 0.4, weight: .bold))
                .foregroundColor(.white)
        }
    }
}
