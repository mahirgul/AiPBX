import SwiftUI

public struct GroupDetailsView: View {
    @Environment(\.presentationMode) private var presentationMode
    @EnvironmentObject private var appState: AppState

    public let conversation: ChatConversation
    @State private var group: ChatConversation
    @State private var isLoading: Bool = false
    @State private var isLeaving: Bool = false
    @State private var showEditSheet: Bool = false
    @State private var editTitle: String = ""
    @State private var editDescription: String = ""
    @State private var showAddMembersSheet: Bool = false
    @State private var selectedNewMembers: Set<String> = []
    @State private var memberToRemove: ChatParticipant? = nil
    @State private var showDeleteGroupConfirm: Bool = false
    @State private var errorMessage: String? = nil

    public init(conversation: ChatConversation) {
        self.conversation = conversation
        _group = State(initialValue: conversation)
    }

    private var myExt: String {
        appState.userProfile?.extensionNumber ?? ""
    }

    private var isAdmin: Bool {
        if group.myRole?.lowercased() == "admin" {
            return true
        }
        if let me = group.participants?.first(where: { $0.extensionNumber == myExt }) {
            return me.role.lowercased() == "admin"
        }
        return false
    }

    private var availableContactsToAdd: [ContactItem] {
        let existingExts = Set((group.participants ?? []).map { $0.extensionNumber })
        return appState.contacts.filter { !existingExts.contains($0.extensionNumber) && $0.extensionNumber != myExt }
    }

    public var body: some View {
        List {
            // Header Section
            Section {
                VStack(spacing: 12) {
                    AvatarView(name: group.displayTitle, size: 72, isGroup: true)

                    Text(group.displayTitle)
                        .font(.title3.bold())

                    if let desc = group.descriptionText, !desc.isEmpty {
                        Text(desc)
                            .font(.subheadline)
                            .foregroundColor(.secondary)
                            .multilineTextAlignment(.center)
                    }

                    Text(L("%@ participants", "\(group.participants?.count ?? group.memberCount ?? 0)"))
                        .font(.caption)
                        .foregroundColor(.secondary)

                    if isAdmin {
                        Button(action: {
                            editTitle = group.title ?? ""
                            editDescription = group.descriptionText ?? ""
                            showEditSheet = true
                        }) {
                            Label(L("Edit Info"), systemImage: "pencil")
                                .font(.caption.bold())
                        }
                        .buttonStyle(.bordered)
                    }
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 12)
            }

            // Participants Section
            Section(header: HStack {
                Text(L("Participants"))
                Spacer()
                if isAdmin {
                    Button(action: {
                        selectedNewMembers.removeAll()
                        showAddMembersSheet = true
                    }) {
                        Text(L("+ Add Member"))
                            .font(.caption.bold())
                    }
                }
            }) {
                if let participants = group.participants, !participants.isEmpty {
                    ForEach(participants) { member in
                        HStack(spacing: 12) {
                            AvatarView(name: member.displayName, size: 36)

                            VStack(alignment: .leading, spacing: 2) {
                                Text(member.displayName)
                                    .font(.system(size: 15, weight: .medium))
                                Text(L("Extension: %@", "\(member.extensionNumber)"))
                                    .font(.caption)
                                    .foregroundColor(.secondary)
                            }

                            Spacer()

                            if member.role.lowercased() == "admin" {
                                Text(L("Admin"))
                                    .font(.caption2.bold())
                                    .padding(.horizontal, 6)
                                    .padding(.vertical, 2)
                                    .background(Color.purple.opacity(0.15))
                                    .foregroundColor(.purple)
                                    .cornerRadius(4)
                            }

                            if isAdmin && member.extensionNumber != myExt {
                                Menu {
                                    if member.role.lowercased() == "admin" {
                                        Button(action: { toggleMemberRole(member, newRole: "member") }) {
                                            Label(L("Remove admin rights"), systemImage: "shield.slash")
                                        }
                                    } else {
                                        Button(action: { toggleMemberRole(member, newRole: "admin") }) {
                                            Label(L("Make admin"), systemImage: "shield")
                                        }
                                    }
                                    Button(role: .destructive, action: { memberToRemove = member }) {
                                        Label(L("Remove from group"), systemImage: "person.badge.minus")
                                    }
                                } label: {
                                    Image(systemName: "ellipsis.circle")
                                        .foregroundColor(.secondary)
                                        .padding(.leading, 4)
                                }
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
                            Text(L("Leave Group"))
                                .fontWeight(.semibold)
                        }
                        Spacer()
                    }
                }
                .disabled(isLeaving)

                if isAdmin {
                    Button(role: .destructive, action: { showDeleteGroupConfirm = true }) {
                        HStack {
                            Spacer()
                            Text(L("Delete Group"))
                                .fontWeight(.semibold)
                            Spacer()
                        }
                    }
                    .disabled(isLeaving)
                }
            }
        }
        .listStyle(InsetGroupedListStyle())
        .navigationBarTitle(L("Group Info"), displayMode: .inline)
        .onAppear {
            loadGroupDetails()
        }
        .sheet(isPresented: $showEditSheet) {
            NavigationView {
                Form {
                    Section(header: Text(L("Group Name"))) {
                        TextField(L("Group Name"), text: $editTitle)
                    }
                    Section(header: Text(L("Group Description"))) {
                        TextField(L("Group Description"), text: $editDescription)
                    }
                }
                .navigationBarTitle(L("Edit Group Info"), displayMode: .inline)
                .navigationBarItems(
                    leading: Button(L("Cancel")) { showEditSheet = false },
                    trailing: Button(L("Save")) { saveGroupInfo() }
                        .disabled(editTitle.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                )
            }
        }
        .sheet(isPresented: $showAddMembersSheet) {
            NavigationView {
                Group {
                    if availableContactsToAdd.isEmpty {
                        VStack(spacing: 12) {
                            Text(L("There are no other extensions to add to the group."))
                                .font(.subheadline)
                                .foregroundColor(.secondary)
                                .multilineTextAlignment(.center)
                                .padding()
                        }
                    } else {
                        List(availableContactsToAdd) { contact in
                            Button(action: {
                                if selectedNewMembers.contains(contact.extensionNumber) {
                                    selectedNewMembers.remove(contact.extensionNumber)
                                } else {
                                    selectedNewMembers.insert(contact.extensionNumber)
                                }
                            }) {
                                HStack {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(contact.name)
                                            .foregroundColor(.primary)
                                        Text(L("Extension: %@", contact.extensionNumber))
                                            .font(.caption)
                                            .foregroundColor(.secondary)
                                    }
                                    Spacer()
                                    if selectedNewMembers.contains(contact.extensionNumber) {
                                        Image(systemName: "checkmark.circle.fill")
                                            .foregroundColor(.blue)
                                    } else {
                                        Image(systemName: "circle")
                                            .foregroundColor(.secondary)
                                    }
                                }
                            }
                        }
                    }
                }
                .navigationBarTitle(L("Add Members to Group"), displayMode: .inline)
                .navigationBarItems(
                    leading: Button(L("Cancel")) { showAddMembersSheet = false },
                    trailing: Button(L("Add Members")) { addSelectedMembers() }
                        .disabled(selectedNewMembers.isEmpty)
                )
            }
        }
        .alert(L("Remove Member"), isPresented: Binding(
            get: { memberToRemove != nil },
            set: { if !$0 { memberToRemove = nil } }
        ), presenting: memberToRemove) { member in
            Button(L("Remove"), role: .destructive) { removeMember(member) }
            Button(L("Cancel"), role: .cancel) {}
        } message: { member in
            Text(L("Remove %@ from the group?", member.displayName))
        }
        .alert(L("Delete Group"), isPresented: $showDeleteGroupConfirm) {
            Button(L("Delete"), role: .destructive) { deleteGroupAction() }
            Button(L("Cancel"), role: .cancel) {}
        } message: {
            Text(L("Are you sure you want to delete this group? It will be removed from every member's chat list."))
        }
        .alert(L("Error"), isPresented: Binding(
            get: { errorMessage != nil },
            set: { if !$0 { errorMessage = nil } }
        )) {
            Button(L("OK"), role: .cancel) {}
        } message: {
            Text(errorMessage ?? "")
        }
    }

    private func loadGroupDetails() {
        guard let token = appState.token else { return }
        isLoading = true
        Task {
            do {
                let updated = try await ApiClient.shared.getGroupDetails(baseUrl: appState.baseUrl, token: token, convId: conversation.id)
                await MainActor.run {
                    self.group = updated
                    self.isLoading = false
                    if let idx = appState.conversations.firstIndex(where: { $0.id == updated.id }) {
                        appState.conversations[idx] = updated
                    }
                }
            } catch {
                await MainActor.run {
                    self.isLoading = false
                }
            }
        }
    }

    private func saveGroupInfo() {
        guard let token = appState.token else { return }
        let newTitle = editTitle.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !newTitle.isEmpty else { return }
        let newDesc = editDescription.trimmingCharacters(in: .whitespacesAndNewlines)
        showEditSheet = false

        Task {
            do {
                _ = try await ApiClient.shared.updateGroupInfo(
                    baseUrl: appState.baseUrl,
                    token: token,
                    convId: conversation.id,
                    title: newTitle,
                    description: newDesc
                )
                loadGroupDetails()
            } catch {
                await MainActor.run {
                    self.errorMessage = error.localizedDescription
                }
            }
        }
    }

    private func addSelectedMembers() {
        guard let token = appState.token else { return }
        let exts = Array(selectedNewMembers)
        guard !exts.isEmpty else { return }
        showAddMembersSheet = false

        Task {
            do {
                _ = try await ApiClient.shared.addGroupMembers(
                    baseUrl: appState.baseUrl,
                    token: token,
                    convId: conversation.id,
                    extensions: exts
                )
                loadGroupDetails()
            } catch {
                await MainActor.run {
                    self.errorMessage = error.localizedDescription
                }
            }
        }
    }

    private func toggleMemberRole(_ member: ChatParticipant, newRole: String) {
        guard let token = appState.token else { return }
        Task {
            do {
                _ = try await ApiClient.shared.updateGroupMemberRole(
                    baseUrl: appState.baseUrl,
                    token: token,
                    convId: conversation.id,
                    extensionNumber: member.extensionNumber,
                    role: newRole
                )
                loadGroupDetails()
            } catch {
                await MainActor.run {
                    self.errorMessage = error.localizedDescription
                }
            }
        }
    }

    private func removeMember(_ member: ChatParticipant) {
        guard let token = appState.token else { return }
        Task {
            do {
                _ = try await ApiClient.shared.removeGroupMember(
                    baseUrl: appState.baseUrl,
                    token: token,
                    convId: conversation.id,
                    extensionNumber: member.extensionNumber
                )
                loadGroupDetails()
            } catch {
                await MainActor.run {
                    self.errorMessage = error.localizedDescription
                }
            }
        }
    }

    private func deleteGroupAction() {
        guard let token = appState.token else { return }
        isLeaving = true
        Task {
            do {
                _ = try await ApiClient.shared.deleteGroup(baseUrl: appState.baseUrl, token: token, convId: conversation.id)
                await MainActor.run {
                    self.isLeaving = false
                    appState.conversations.removeAll(where: { $0.id == conversation.id })
                    presentationMode.wrappedValue.dismiss()
                }
            } catch {
                await MainActor.run {
                    self.isLeaving = false
                    self.errorMessage = error.localizedDescription
                }
            }
        }
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
                    self.errorMessage = error.localizedDescription
                }
            }
        }
    }
}
