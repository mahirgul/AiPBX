import SwiftUI

public struct MainTabView: View {
    @EnvironmentObject private var appState: AppState
    @State private var selectedTab: Int = 0

    public init() {}

    private var totalUnreadMessages: Int {
        return appState.conversations.reduce(0) { $0 + $1.unreadCount }
    }

    public var body: some View {
        TabView(selection: $selectedTab) {
            // Tab 1: keypad
            DialerTabView()
                .tabItem {
                    Image(systemName: "circle.grid.3x3.fill")
                    Text(L("Keypad"))
                }
                .tag(0)

            // Tab 2: history
            CallHistoryTabView()
                .tabItem {
                    Image(systemName: "clock.fill")
                    Text(L("History"))
                }
                .tag(1)

            // Tab 3: Rehber
            ContactsTabView()
                .tabItem {
                    Image(systemName: "person.2.fill")
                    Text(L("Contacts"))
                }
                .tag(2)

            // Tab 4: Sohbet
            ChatTabView()
                .tabItem {
                    Image(systemName: "bubble.left.and.bubble.right.fill")
                    Text(L("Chat"))
                }
                .badge(totalUnreadMessages > 0 ? "\(totalUnreadMessages)" : nil)
                .tag(3)

            // Tab 5: Santral
            SettingsTabView()
                .tabItem {
                    Image(systemName: "slider.horizontal.3")
                    Text(L("PBX"))
                }
                .tag(4)
        }
        .accentColor(.blue)
        .fullScreenCover(isPresented: $appState.isCallSheetPresented) {
            ActiveCallView()
                .environmentObject(appState)
        }
    }
}
