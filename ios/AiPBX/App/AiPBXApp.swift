import SwiftUI

@main
struct AiPBXApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate
    @StateObject private var appState = AppState.shared

    var body: some Scene {
        WindowGroup {
            Group {
                if appState.isLoggedIn {
                    MainTabView()
                } else {
                    LoginView()
                }
            }
            .environmentObject(appState)
            .preferredColorScheme(.none) // Supports both Dark and Light mode automatically
            .onOpenURL { url in
                appState.handleDeepLinkUrl(url)
            }
            .alert(item: $appState.pendingLinkLogin) { pending in
                Alert(
                    title: Text("Mobil Giriş"),
                    message: Text("\"\(pending.host)\" santraline giriş yapılsın mı?\n\nBu bağlantıyı yalnızca kurumunuzdan gelen bir e-postadan veya kendi ekranınızdaki QR koddan açtıysanız onaylayın."
                                  + (appState.isLoggedIn ? "\n\nAçık olan oturum, giriş başarılı olursa kapatılacak." : "")),
                    primaryButton: .default(Text("Giriş Yap")) { appState.confirmPendingLinkLogin() },
                    secondaryButton: .cancel(Text("İptal"))
                )
            }
        }
    }
}
