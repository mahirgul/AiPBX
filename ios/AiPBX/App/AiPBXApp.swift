import SwiftUI

@main
struct AiPBXApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate
    @StateObject private var appState = AppState.shared
    @StateObject private var language = AppLanguage.shared

    var body: some Scene {
        WindowGroup {
            Group {
                if appState.isLoggedIn {
                    MainTabView()
                } else {
                    LoginView()
                }
            }
            // A new language rebuilds every screen with the new texts.
            .id(language.code)
            .environment(\.locale, Locale(identifier: language.code))
            .environmentObject(appState)
            .preferredColorScheme(.none) // Supports both Dark and Light mode automatically
            .onChange(of: language.code) { _ in
                // The Reply action's titles follow the app language.
                ChatNotifications.shared.registerCategory()
            }
            .onOpenURL { url in
                appState.handleDeepLinkUrl(url)
            }
            .alert(item: $appState.pendingLinkLogin) { pending in
                Alert(
                    title: Text(L("Mobile Sign-in")),
                    message: Text(L("Sign in to the \"%@\" PBX?\n\nOnly confirm if you opened this link from an e-mail sent by your organization or from the QR code on your own screen.", pending.host)
                                  + (appState.isLoggedIn ? "\n\n" + L("The open session is closed if this sign-in succeeds.") : "")),
                    primaryButton: .default(Text(L("Sign In"))) { appState.confirmPendingLinkLogin() },
                    secondaryButton: .cancel(Text(L("Cancel")))
                )
            }
        }
    }
}
