import Foundation
import SwiftUI

/// App language: English by default, Turkish when the user picks it (login
/// screen or Settings). English text is the key; tr.lproj/Localizable.strings
/// holds the Turkish. Changing it rebuilds the screens (AiPBXApp uses the code
/// as the root view's id), no restart needed.
final class AppLanguage: ObservableObject {
    static let shared = AppLanguage()
    static let supported = ["en", "tr"]
    /// Names in their own language, so they are recognisable whatever is active.
    static let names = ["en": "English", "tr": "Türkçe"]
    private static let storeKey = "app_language"

    @Published private(set) var code: String
    fileprivate private(set) var bundle: Bundle?

    private init() {
        let saved = UserDefaults.standard.string(forKey: Self.storeKey) ?? "en"
        code = Self.supported.contains(saved) ? saved : "en"
        bundle = Self.bundle(for: code)
    }

    var displayName: String { Self.names[code] ?? code }

    func set(_ newCode: String) {
        guard Self.supported.contains(newCode), newCode != code else { return }
        UserDefaults.standard.set(newCode, forKey: Self.storeKey)
        bundle = Self.bundle(for: newCode)
        code = newCode
    }

    private static func bundle(for code: String) -> Bundle? {
        guard code != "en", let path = Bundle.main.path(forResource: code, ofType: "lproj") else { return nil }
        return Bundle(path: path)
    }
}

/// Localized text for an English key; %@ placeholders are filled from args.
public func L(_ key: String, _ args: CVarArg...) -> String {
    let text = AppLanguage.shared.bundle?.localizedString(forKey: key, value: key, table: nil) ?? key
    return args.isEmpty ? text : String(format: text, arguments: args)
}

/// English / Türkçe menu used on the login screen.
struct LanguageMenu: View {
    @ObservedObject private var language = AppLanguage.shared

    var body: some View {
        Menu {
            ForEach(AppLanguage.supported, id: \.self) { code in
                Button(AppLanguage.names[code] ?? code) { language.set(code) }
            }
        } label: {
            Label(language.displayName, systemImage: "globe")
                .font(.footnote.weight(.semibold))
        }
        .accessibilityLabel(L("Language"))
    }
}
