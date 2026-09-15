import SwiftUI

public struct ChatTabView: View {
    @EnvironmentObject private var appState: AppState
    @State private var selectedFilter: String = "all"
    @State private var showNewGroupSheet: Bool = false

    private let filters: [(id: String, label: String)] = [
        ("all", "Tümü"),
        ("direct", "Bireysel"),
        ("group", "Gruplar")
    ]

    public init() {}

    private var filteredConversations: [ChatConversation] {
        if selectedFilter == "direct" {
            return appState.conversations.filter { !$0.isGroup }
        } else if selectedFilter == "group" {
            return appState.conversations.filter { $0.isGroup }
        }
        return appState.conversations
    }

    public var body: some View {
        NavigationView {
            VStack(spacing: 0) {
                // Header with Filter Chips & New Group Button
                HStack {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 8) {
                            ForEach(filters, id: \.id) { item in
                                Button(action: {
                                    selectedFilter = item.id
                                }) {
                                    Text(item.label)
                                        .font(.subheadline.weight(selectedFilter == item.id ? .semibold : .regular))
                                        .padding(.horizontal, 14)
                                        .padding(.vertical, 7)
                                        .background(selectedFilter == item.id ? Color.blue : Color(.systemGray6))
                                        .foregroundColor(selectedFilter == item.id ? .white : .primary)
                                        .cornerRadius(18)
                                }
                            }
                        }
                    }

                    Spacer()

                    Button(action: { showNewGroupSheet = true }) {
                        HStack(spacing: 4) {
                            Image(systemName: "plus")
                            Text("Yeni Grup")
                        }
                        .font(.footnote.bold())
                        .padding(.horizontal, 10)
                        .padding(.vertical, 7)
                        .background(Color.purple.opacity(0.15))
                        .foregroundColor(.purple)
                        .cornerRadius(18)
                    }
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 10)
                .background(Color(.systemBackground))

                Divider()

                // Conversation List
                if filteredConversations.isEmpty {
                    Spacer()
                    VStack(spacing: 12) {
                        Image(systemName: "bubble.left.and.bubble.right")
                            .font(.system(size: 48))
                            .foregroundColor(.secondary.opacity(0.6))
                        Text("Sohbet bulunamadı")
                            .font(.subheadline)
                            .foregroundColor(.secondary)
                    }
                    Spacer()
                } else {
                    List {
                        ForEach(filteredConversations) { conv in
                            NavigationLink(destination: ChatRoomView(conversation: conv)) {
                                ChatConversationRowView(conversation: conv)
                            }
                        }
                    }
                    .listStyle(PlainListStyle())
                    .refreshable {
                        await appState.refreshAllData()
                    }
                }
            }
            .navigationBarTitle("Sohbet", displayMode: .inline)
            .sheet(isPresented: $showNewGroupSheet) {
                NewGroupChatView()
                    .environmentObject(appState)
            }
        }
        .navigationViewStyle(StackNavigationViewStyle())
    }
}
