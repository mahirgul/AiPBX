import SwiftUI
import Contacts

public struct ContactsTabView: View {
    @EnvironmentObject private var appState: AppState
    @State private var searchText: String = ""
    @State private var selectedTab: Int = 0 // 0 = Company, 1 = Phone
    @State private var deviceContacts: [ContactItem] = []
    @State private var isLoadingDeviceContacts: Bool = false
    @State private var selectedConversation: ChatConversation? = nil
    @State private var isOpeningChat: Bool = false

    public init() {}

    private var activeList: [ContactItem] {
        selectedTab == 0 ? appState.contacts : deviceContacts
    }

    private var filteredContacts: [ContactItem] {
        if searchText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            return activeList
        }
        let q = searchText.lowercased()
        return activeList.filter {
            $0.name.lowercased().contains(q) || $0.extensionNumber.lowercased().contains(q)
        }
    }

    public var body: some View {
        NavigationView {
            VStack(spacing: 0) {
                Picker("", selection: $selectedTab) {
                    Text(L("Company Extensions")).tag(0)
                    Text(L("Phone Contacts")).tag(1)
                }
                .pickerStyle(SegmentedPickerStyle())
                .padding(.horizontal, 16)
                .padding(.top, 8)
                .onChange(of: selectedTab) { newValue in
                    if newValue == 1 && deviceContacts.isEmpty {
                        loadDeviceContacts()
                    }
                }

                // Search Bar
                SearchBarView(text: $searchText, placeholder: L("Search name or extension..."))
                    .padding(.horizontal, 16)
                    .padding(.vertical, 8)

                Divider()

                if selectedTab == 1 && isLoadingDeviceContacts {
                    Spacer()
                    ProgressView()
                    Spacer()
                } else if filteredContacts.isEmpty {
                    Spacer()
                    VStack(spacing: 12) {
                        Image(systemName: "person.2.slash")
                            .font(.system(size: 48))
                            .foregroundColor(.secondary.opacity(0.6))
                        Text(selectedTab == 1 && deviceContacts.isEmpty ? L("No phone contacts found, or contacts permission was not granted.") : L("No contacts found"))
                            .font(.subheadline)
                            .foregroundColor(.secondary)
                            .multilineTextAlignment(.center)
                            .padding(.horizontal, 24)
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
                        if selectedTab == 0 {
                            await appState.refreshAllData()
                        } else {
                            loadDeviceContacts()
                        }
                    }
                }
            }
            .navigationBarTitle(L("Contacts (%@)", "\(activeList.count)"), displayMode: .inline)
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

    private func loadDeviceContacts() {
        isLoadingDeviceContacts = true
        let store = CNContactStore()
        store.requestAccess(for: .contacts) { granted, _ in
            guard granted else {
                DispatchQueue.main.async {
                    self.deviceContacts = []
                    self.isLoadingDeviceContacts = false
                }
                return
            }

            DispatchQueue.global(qos: .userInitiated).async {
                let keys: [CNKeyDescriptor] = [
                    CNContactGivenNameKey as CNKeyDescriptor,
                    CNContactFamilyNameKey as CNKeyDescriptor,
                    CNContactPhoneNumbersKey as CNKeyDescriptor
                ]
                let request = CNContactFetchRequest(keysToFetch: keys)
                var items: [ContactItem] = []
                var seenNumbers = Set<String>()

                try? store.enumerateContacts(with: request) { cnContact, _ in
                    let fullName = [cnContact.givenName, cnContact.familyName]
                        .map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }
                        .filter { !$0.isEmpty }
                        .joined(separator: " ")
                    let displayName = fullName.isEmpty ? L("Unnamed") : fullName

                    for phone in cnContact.phoneNumbers {
                        let raw = phone.value.stringValue
                        let cleaned = raw.replacingOccurrences(of: "[^0-9+*#]", with: "", options: .regularExpression)
                        guard !cleaned.isEmpty, !seenNumbers.contains(cleaned) else { continue }
                        seenNumbers.insert(cleaned)
                        items.append(
                            ContactItem(
                                extensionNumber: cleaned,
                                name: displayName,
                                role: "device",
                                roleName: L("Phone Contact"),
                                status: "offline"
                            )
                        )
                    }
                }

                items.sort { $0.name.localizedCaseInsensitiveCompare($1.name) == .orderedAscending }
                DispatchQueue.main.async {
                    self.deviceContacts = items
                    self.isLoadingDeviceContacts = false
                }
            }
        }
    }

    private func openChatWith(contact: ContactItem) {
        guard contact.role != "device" else { return }
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
