import SwiftUI
import PhotosUI
import UniformTypeIdentifiers

public struct ChatRoomView: View {
    @EnvironmentObject private var appState: AppState
    public let conversation: ChatConversation

    @State private var messages: [ChatMessage] = []
    @State private var inputText: String = ""
    @State private var isLoadingMessages: Bool = false
    @State private var typingUser: String? = nil
    @State private var showGroupDetails: Bool = false
    @State private var messageToDelete: ChatMessage? = nil
    @State private var deleteError: String? = nil
    @State private var selectedPhotoItem: PhotosPickerItem? = nil
    @State private var showFileImporter: Bool = false
    @State private var isUploading: Bool = false

    public init(conversation: ChatConversation) {
        self.conversation = conversation
    }

    public var body: some View {
        VStack(spacing: 0) {
            // Messages Scroll View
            ScrollViewReader { proxy in
                ScrollView {
                    LazyVStack(spacing: 6) {
                        ForEach(messages) { msg in
                            ChatMessageBubbleView(
                                message: msg,
                                isGroup: conversation.isGroup,
                                onDelete: canDelete(msg) ? { messageToDelete = msg } : nil
                            )
                            .id(msg.id)
                        }
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 8)
                }
                .onChange(of: messages.count) { _ in
                    if let last = messages.last {
                        withAnimation {
                            proxy.scrollTo(last.id, anchor: .bottom)
                        }
                    }
                }
            }

            if isUploading {
                HStack(spacing: 8) {
                    ProgressView()
                        .scaleEffect(0.8)
                    Text(L("Preparing and uploading the file..."))
                        .font(.caption)
                        .foregroundColor(.secondary)
                    Spacer()
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 6)
                .background(Color(.systemGray6))
            }

            // Typing Indicator Banner
            if let typing = typingUser {
                HStack(spacing: 6) {
                    Text(L("%@ is typing...", "\(typing)"))
                        .font(.caption)
                        .foregroundColor(.secondary)
                        .italic()
                    Spacer()
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 4)
                .background(Color(.systemGray6))
            }

            Divider()

            // Input Bar
            HStack(spacing: 10) {
                Menu {
                    PhotosPicker(selection: $selectedPhotoItem, matching: .images) {
                        Label(L("Choose from gallery"), systemImage: "photo.on.rectangle")
                    }
                    Button(action: { showFileImporter = true }) {
                        Label(L("Attach File"), systemImage: "doc")
                    }
                } label: {
                    Image(systemName: "paperclip")
                        .font(.system(size: 20, weight: .medium))
                        .foregroundColor(.blue)
                        .padding(6)
                }
                .disabled(isUploading)

                TextField(L("Type a message..."), text: $inputText)
                    .textFieldStyle(PlainTextFieldStyle())
                    .padding(10)
                    .background(Color(.systemGray6))
                    .cornerRadius(20)
                    .onChange(of: inputText) { newVal in
                        ChatWebSocketManager.shared.sendTyping(conversationId: conversation.id, isTyping: !newVal.isEmpty)
                    }

                Button(action: sendCurrentMessage) {
                    Image(systemName: "arrow.up.circle.fill")
                        .font(.system(size: 32))
                        .foregroundColor(inputText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? Color.gray.opacity(0.4) : Color.blue)
                }
                .disabled(inputText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(Color(.systemBackground))
        }
        .navigationBarTitle(conversation.displayTitle, displayMode: .inline)
        .navigationBarItems(trailing: groupDetailsButton)
        .onAppear {
            appState.activeConversationId = conversation.id
            loadMessages()
        }
        .onDisappear {
            appState.activeConversationId = nil
        }
        .sheet(isPresented: $showGroupDetails) {
            NavigationView {
                GroupDetailsView(conversation: conversation)
                    .environmentObject(appState)
            }
        }
        .onChange(of: selectedPhotoItem) { newItem in
            guard let item = newItem else { return }
            selectedPhotoItem = nil
            Task {
                if let data = try? await item.loadTransferable(type: Data.self) {
                    let ext = item.supportedContentTypes.first?.preferredFilenameExtension ?? "jpg"
                    let mime = item.supportedContentTypes.first?.preferredMIMEType ?? "image/jpeg"
                    let fileName = "photo_\(Int(Date().timeIntervalSince1970)).\(ext)"
                    await uploadAndSend(fileData: data, fileName: fileName, mimeType: mime)
                } else {
                    await MainActor.run {
                        deleteError = L("Could not read the file.")
                    }
                }
            }
        }
        .fileImporter(
            isPresented: $showFileImporter,
            allowedContentTypes: [.item],
            allowsMultipleSelection: false
        ) { result in
            switch result {
            case .success(let urls):
                guard let url = urls.first else { return }
                let accessed = url.startAccessingSecurityScopedResource()
                defer {
                    if accessed { url.stopAccessingSecurityScopedResource() }
                }
                if let data = try? Data(contentsOf: url) {
                    let fileName = url.lastPathComponent
                    let mime = UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "application/octet-stream"
                    Task {
                        await uploadAndSend(fileData: data, fileName: fileName, mimeType: mime)
                    }
                } else {
                    deleteError = L("Could not read the file.")
                }
            case .failure(let error):
                deleteError = L("Upload error: %@", error.localizedDescription)
            }
        }
        .onReceive(appState.chatMessageReceived) { msg in
            receive(msg)
        }
        .onReceive(appState.chatMessageDeleted) { event in
            guard event.conversationId == conversation.id else { return }
            markDeleted(event.messageId)
        }
        .alert(L("Delete message"), isPresented: Binding(
            get: { messageToDelete != nil },
            set: { if !$0 { messageToDelete = nil } }
        ), presenting: messageToDelete) { msg in
            Button(L("Delete"), role: .destructive) { deleteMessage(msg) }
            Button(L("Cancel"), role: .cancel) {}
        } message: { _ in
            Text(L("Delete this message for everyone in the chat?"))
        }
        .alert(L("Error"), isPresented: Binding(
            get: { deleteError != nil },
            set: { if !$0 { deleteError = nil } }
        )) {
            Button(L("OK"), role: .cancel) {}
        } message: {
            Text(deleteError ?? "")
        }
    }

    /// Only the sender can delete a message, and only one the server has stored.
    private func canDelete(_ msg: ChatMessage) -> Bool {
        return (msg.isMe ?? false) && !msg.isSystem && !(msg.isDeleted ?? false) && !msg.isLocal
    }

    private func deleteMessage(_ msg: ChatMessage) {
        guard let token = appState.token else { return }
        let baseUrl = appState.baseUrl
        Task { @MainActor in
            do {
                try await ApiClient.shared.deleteChatMessage(baseUrl: baseUrl, token: token, messageId: msg.id)
                // The "message_deleted" event follows; do not wait for it.
                markDeleted(msg.id)
            } catch {
                deleteError = error.localizedDescription
            }
        }
    }

    private func markDeleted(_ messageId: Int64) {
        if let idx = messages.firstIndex(where: { $0.id == messageId }) {
            messages[idx] = messages[idx].markedDeleted()
        }
    }

    /// A live message: the server's copy of one just sent here replaces the
    /// local one (it carries the real id, so it can be deleted).
    private func receive(_ msg: ChatMessage) {
        guard msg.conversationId == conversation.id,
              !messages.contains(where: { $0.id == msg.id }) else { return }
        if (msg.isMe ?? false),
           let idx = messages.firstIndex(where: {
               $0.isLocal && (
                   ($0.attachmentUrl != nil && $0.attachmentUrl == msg.attachmentUrl) ||
                   ($0.attachmentUrl == nil && $0.message == msg.message)
               )
           }) {
            messages[idx] = msg
            return
        }
        messages.append(msg)
        if !(msg.isMe ?? false) {
            ChatWebSocketManager.shared.markAsRead(conversationId: conversation.id, lastMessageId: msg.id)
        }
    }

    @ViewBuilder
    private var groupDetailsButton: some View {
        if conversation.isGroup {
            Button(action: { showGroupDetails = true }) {
                Image(systemName: "info.circle")
            }
        }
    }

    private func loadMessages() {
        guard let token = appState.token else { return }
        isLoadingMessages = true

        Task {
            do {
                let msgs = try await ApiClient.shared.getChatMessages(baseUrl: appState.baseUrl, token: token, convId: conversation.id)
                await MainActor.run {
                    self.messages = msgs
                    self.isLoadingMessages = false
                    self.markRead()
                }
            } catch {
                await MainActor.run {
                    self.isLoadingMessages = false
                }
            }
        }
    }

    private func uploadAndSend(fileData: Data, fileName: String, mimeType: String) async {
        guard let token = appState.token else { return }
        await MainActor.run { isUploading = true }
        let captionText = inputText.trimmingCharacters(in: .whitespacesAndNewlines)
        do {
            let uploaded = try await ApiClient.shared.uploadChatFile(
                baseUrl: appState.baseUrl,
                token: token,
                fileData: fileData,
                fileName: fileName,
                mimeType: mimeType
            )
            await MainActor.run {
                self.isUploading = false
                self.inputText = ""
                let msgType = uploaded.msgType ?? (mimeType.hasPrefix("image/") ? "image" : "file")
                let url = uploaded.attachmentUrl ?? ""
                let name = uploaded.fileName ?? fileName
                let size = uploaded.fileSize ?? Int64(fileData.count)
                let mime = uploaded.mimeType ?? mimeType

                ChatWebSocketManager.shared.sendAttachment(
                    conversationId: conversation.id,
                    msgType: msgType,
                    message: captionText,
                    attachmentUrl: url,
                    fileName: name,
                    fileSize: size,
                    mimeType: mime
                )

                let myExt = appState.userProfile?.extensionNumber ?? ""
                let myName = appState.userProfile?.displayName ?? L("You")
                let df = DateFormatter()
                df.dateFormat = "yyyy-MM-dd HH:mm:ss"
                let nowStr = df.string(from: Date())

                let optimisticMsg = ChatMessage(
                    id: Int64(Date().timeIntervalSince1970 * 1000),
                    conversationId: conversation.id,
                    senderExt: myExt,
                    senderName: myName,
                    msgType: msgType,
                    message: captionText.isEmpty ? nil : captionText,
                    attachmentUrl: url,
                    fileName: name,
                    fileSize: size,
                    mimeType: mime,
                    createdAt: nowStr,
                    isMe: true,
                    systemEvent: nil,
                    systemMeta: nil,
                    isLocal: true
                )
                self.messages.append(optimisticMsg)
            }
        } catch {
            await MainActor.run {
                self.isUploading = false
                self.deleteError = L("Upload error: %@", error.localizedDescription)
            }
        }
    }

    private func sendCurrentMessage() {
        let text = inputText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { return }

        ChatWebSocketManager.shared.sendMessage(conversationId: conversation.id, message: text)
        inputText = ""

        // Optimistic local append
        let myExt = appState.userProfile?.extensionNumber ?? ""
        let myName = appState.userProfile?.displayName ?? L("You")
        let df = DateFormatter()
        df.dateFormat = "yyyy-MM-dd HH:mm:ss"
        let nowStr = df.string(from: Date())

        let optimisticMsg = ChatMessage(
            id: Int64(Date().timeIntervalSince1970 * 1000),
            conversationId: conversation.id,
            senderExt: myExt,
            senderName: myName,
            msgType: "text",
            message: text,
            attachmentUrl: nil,
            fileName: nil,
            fileSize: nil,
            mimeType: nil,
            createdAt: nowStr,
            isMe: true,
            systemEvent: nil,
            systemMeta: nil,
            isLocal: true
        )
        messages.append(optimisticMsg)
    }

    private func markRead() {
        if let lastId = messages.last?.id {
            ChatWebSocketManager.shared.markAsRead(conversationId: conversation.id, lastMessageId: lastId)
        }
        if let idx = appState.conversations.firstIndex(where: { $0.id == conversation.id }) {
            appState.conversations[idx].unreadCount = 0
        }
    }
}
