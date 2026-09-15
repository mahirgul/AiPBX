import SwiftUI

public struct NewGroupChatView: View {
    @Environment(\.presentationMode) private var presentationMode
    @EnvironmentObject private var appState: AppState

    @State private var groupTitle: String = ""
    @State private var selectedExtensions: Set<String> = []
    @State private var isCreating: Bool = false
    @State private var errorMessage: String? = nil

    public var onCreated: ((ChatConversation) -> Void)?

    public init(onCreated: ((ChatConversation) -> Void)? = nil) {
        self.onCreated = onCreated
    }

    public var body: some View {
        NavigationView {
            VStack(spacing: 0) {
                // Group Title Input Card
                VStack(alignment: .leading, spacing: 8) {
                    Text("Grup Başlığı")
                        .font(.caption)
                        .foregroundColor(.secondary)

                    TextField("Örn: Satış ve Pazarlama", text: $groupTitle)
                        .padding(12)
                        .background(Color(.systemGray6))
                        .cornerRadius(10)
                }
                .padding(16)

                if let err = errorMessage {
                    Text(err)
                        .font(.footnote)
                        .foregroundColor(.red)
                        .padding(.horizontal, 16)
                }

                // Participant Header
                HStack {
                    Text("Katılımcılar (\(selectedExtensions.count))")
                        .font(.subheadline.bold())
                        .foregroundColor(.secondary)
                    Spacer()
                }
                .padding(.horizontal, 16)
                .padding(.top, 8)
                .padding(.bottom, 4)

                // Contact Selection List
                List {
                    ForEach(appState.contacts) { contact in
                        HStack {
                            AvatarView(name: contact.name, size: 36)

                            VStack(alignment: .leading, spacing: 2) {
                                Text(contact.name)
                                    .font(.system(size: 15, weight: .medium))
                                Text("Dahili: \(contact.extensionNumber)")
                                    .font(.caption)
                                    .foregroundColor(.secondary)
                            }

                            Spacer()

                            Image(systemName: selectedExtensions.contains(contact.extensionNumber) ? "checkmark.circle.fill" : "circle")
                                .font(.title3)
                                .foregroundColor(selectedExtensions.contains(contact.extensionNumber) ? .blue : .secondary.opacity(0.5))
                        }
                        .contentShape(Rectangle())
                        .onTapGesture {
                            if selectedExtensions.contains(contact.extensionNumber) {
                                selectedExtensions.remove(contact.extensionNumber)
                            } else {
                                selectedExtensions.insert(contact.extensionNumber)
                            }
                        }
                    }
                }
                .listStyle(PlainListStyle())
            }
            .navigationBarTitle("Yeni Grup", displayMode: .inline)
            .navigationBarItems(
                leading: Button("İptal") {
                    presentationMode.wrappedValue.dismiss()
                },
                trailing: Button("Oluştur") {
                    createGroup()
                }
                .disabled(groupTitle.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || selectedExtensions.isEmpty || isCreating)
            )
        }
    }

    private func createGroup() {
        guard let token = appState.token else { return }
        isCreating = true
        errorMessage = nil

        let title = groupTitle.trimmingCharacters(in: .whitespacesAndNewlines)
        let members = Array(selectedExtensions)

        Task {
            do {
                let conv = try await ApiClient.shared.createGroupChat(
                    baseUrl: appState.baseUrl,
                    token: token,
                    title: title,
                    members: members
                )
                await MainActor.run {
                    self.isCreating = false
                    if !appState.conversations.contains(where: { $0.id == conv.id }) {
                        appState.conversations.insert(conv, at: 0)
                    }
                    presentationMode.wrappedValue.dismiss()
                    onCreated?(conv)
                }
            } catch {
                await MainActor.run {
                    self.isCreating = false
                    self.errorMessage = error.localizedDescription
                }
            }
        }
    }
}
