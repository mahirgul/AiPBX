import SwiftUI

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
            markRead()
        }
        .onDisappear {
            appState.activeConversationId = nil
        }
        .sheet(isPresented: $showGroupDetails) {
            GroupDetailsView(conversation: conversation)
                .environmentObject(appState)
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
           let idx = messages.firstIndex(where: { $0.isLocal && $0.message == msg.message }) {
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
                }
            } catch {
                await MainActor.run {
                    self.isLoadingMessages = false
                }
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
        let myName = appState.userProfile?.displayName ?? "Ben"
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
