import SwiftUI

public struct ContactsTabView: View {
    @EnvironmentObject private var appState: AppState
    @State private var searchText: String = ""
    @State private var selectedConversation: ChatConversation? = nil
    @State private var isOpeningChat: Bool = false

    public init() {}

    private var filteredContacts: [ContactItem] {
        if searchText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            return appState.contacts
        }
        let q = searchText.lowercased()
        return appState.contacts.filter {
            $0.name.lowercased().contains(q) || $0.extensionNumber.contains(q)
        }
    }

    public var body: some View {
        NavigationView {
            VStack(spacing: 0) {
                // Search Bar
                SearchBarView(text: $searchText, placeholder: "İsim veya dahili ara...")
                    .padding(.horizontal, 16)
                    .padding(.vertical, 8)

                Divider()

                if filteredContacts.isEmpty {
                    Spacer()
                    VStack(spacing: 12) {
                        Image(systemName: "person.2.slash")
                            .font(.system(size: 48))
                            .foregroundColor(.secondary.opacity(0.6))
                        Text("Kişi bulunamadı")
                            .font(.subheadline)
                            .foregroundColor(.secondary)
                    }
                    Spacer()
                } else {
                    List {
                        ForEach(filteredContacts) { contact in
                            ContactRowView(contact: contact, onCall: {
                                appState.makeCall(to: contact.extensionNumber)
                            }, onChat: {
                                openChatWith(contact: contact)
                            })
                        }
                    }
                    .listStyle(PlainListStyle())
                    .refreshable {
                        await appState.refreshAllData()
                    }
                }
            }
            .navigationBarTitle("Rehber (\(appState.contacts.count))", displayMode: .inline)
            .background(
                NavigationLink(
                    destination: selectedConversation.map { ChatRoomView(conversation: $0) },
                    isActive: $isOpeningChat
                ) {
                    EmptyView()
                }
                .hidden()
            )
        }
        .navigationViewStyle(StackNavigationViewStyle())
    }

    private func openChatWith(contact: ContactItem) {
        // If conversation already exists in appState, open it
        if let existing = appState.conversations.first(where: {
            !$0.isGroup && ($0.targetExt == contact.extensionNumber || $0.directKey?.contains(contact.extensionNumber) == true)
        }) {
            self.selectedConversation = existing
            self.isOpeningChat = true
            return
        }

        // Create new direct chat via API
        Task {
            guard let token = appState.token else { return }
            do {
                let conv = try await ApiClient.shared.createDirectChat(
                    baseUrl: appState.baseUrl,
                    token: token,
                    targetExt: contact.extensionNumber
                )
                await MainActor.run {
                    if !appState.conversations.contains(where: { $0.id == conv.id }) {
                        appState.conversations.insert(conv, at: 0)
                    }
                    self.selectedConversation = conv
                    self.isOpeningChat = true
                }
            } catch {
                AppLogManager.shared.error("Contacts", "Failed to start direct chat: \(error.localizedDescription)")
            }
        }
    }
}
