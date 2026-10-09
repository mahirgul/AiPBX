import Foundation
import Security

/// Keeps the mobile session (server address + 30-day bearer token) in the
/// Keychain, so the app can sign itself back in after iOS relaunches it in the
/// background for a VoIP push (incoming call) or a notification Reply. Android
/// keeps the same values in its preferences. The SIP password is never stored:
/// it comes back from api/mobile/refresh.php with a fresh token.
public enum SessionStore {
    private static let service = "com.mhrgl.AiPBX.session"
    private static let account = "mobile_session"

    public struct Saved: Codable {
        public let baseUrl: String
        public let token: String
    }

    public static func save(baseUrl: String, token: String) {
        guard let data = try? JSONEncoder().encode(Saved(baseUrl: baseUrl, token: token)) else { return }
        clear()
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecValueData as String: data,
            // Readable while the phone is locked (after the first unlock since
            // boot): a call can arrive with the screen locked.
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        ]
        let status = SecItemAdd(query as CFDictionary, nil)
        if status != errSecSuccess {
            AppLogManager.shared.warn("SessionStore", "Could not save the session (\(status))")
        }
    }

    public static func load() -> Saved? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var item: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
              let data = item as? Data else { return nil }
        return try? JSONDecoder().decode(Saved.self, from: data)
    }

    public static func clear() {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account
        ]
        SecItemDelete(query as CFDictionary)
    }
}
