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
        }
    }
}
