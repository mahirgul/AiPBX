import SwiftUI

public struct ChatRoomView: View {
    @EnvironmentObject private var appState: AppState
    public let conversation: ChatConversation

    @State private var messages: [ChatMessage] = []
    @State private var inputText: String = ""
    @State private var isLoadingMessages: Bool = false
    @State private var typingUser: String? = nil
    @State private var showGroupDetails: Bool = false

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
                            ChatMessageBubbleView(message: msg, isGroup: conversation.isGroup)
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
                    Text("\(typing) yazıyor...")
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
                TextField("Mesaj yazın...", text: $inputText)
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
            systemMeta: nil
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
