import SwiftUI

public struct GroupDetailsView: View {
    @Environment(\.presentationMode) private var presentationMode
    @EnvironmentObject private var appState: AppState

    public let conversation: ChatConversation
    @State private var isLeaving: Bool = false

    public init(conversation: ChatConversation) {
        self.conversation = conversation
    }

    public var body: some View {
        List {
            // Header Section
            Section {
                VStack(spacing: 12) {
                    AvatarView(name: conversation.displayTitle, size: 72, isGroup: true)

                    Text(conversation.displayTitle)
                        .font(.title3.bold())

                    if let desc = conversation.descriptionText, !desc.isEmpty {
                        Text(desc)
                            .font(.subheadline)
                            .foregroundColor(.secondary)
                    }

                    Text("\(conversation.memberCount ?? 0) Katılımcı")
                        .font(.caption)
                        .foregroundColor(.secondary)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 12)
            }

            // Participants Section
            if let participants = conversation.participants, !participants.isEmpty {
                Section(header: Text("Katılımcılar")) {
                    ForEach(participants) { member in
                        HStack(spacing: 12) {
                            AvatarView(name: member.displayName, size: 36)

                            VStack(alignment: .leading, spacing: 2) {
                                Text(member.displayName)
                                    .font(.system(size: 15, weight: .medium))
                                Text("Dahili: \(member.extensionNumber)")
                                    .font(.caption)
                                    .foregroundColor(.secondary)
                            }

                            Spacer()

                            if member.role.lowercased() == "admin" {
                                Text("Yönetici")
                                    .font(.caption2.bold())
                                    .padding(.horizontal, 6)
                                    .padding(.vertical, 2)
                                    .background(Color.purple.opacity(0.15))
                                    .foregroundColor(.purple)
                                    .cornerRadius(4)
                            }
                        }
                    }
                }
            }

            // Actions Section
            Section {
                Button(role: .destructive, action: leaveGroup) {
                    HStack {
                        Spacer()
                        if isLeaving {
                            ProgressView()
                        } else {
                            Text("Gruptan Ayrıl")
                                .fontWeight(.semibold)
                        }
                        Spacer()
                    }
                }
                .disabled(isLeaving)
            }
        }
        .listStyle(InsetGroupedListStyle())
        .navigationBarTitle("Grup Bilgisi", displayMode: .inline)
    }

    private func leaveGroup() {
        guard let token = appState.token else { return }
        isLeaving = true

        Task {
            do {
                _ = try await ApiClient.shared.leaveGroup(baseUrl: appState.baseUrl, token: token, convId: conversation.id)
                await MainActor.run {
                    self.isLeaving = false
                    appState.conversations.removeAll(where: { $0.id == conversation.id })
                    presentationMode.wrappedValue.dismiss()
                }
            } catch {
                await MainActor.run {
                    self.isLeaving = false
                }
            }
        }
    }
}
