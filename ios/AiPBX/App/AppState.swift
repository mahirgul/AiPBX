import Foundation
import Combine
import SwiftUI

@MainActor
public final class AppState: ObservableObject, SipWebRtcEngineDelegate, ChatWebSocketDelegate {
    public static let shared = AppState()

    // MARK: - Auth & Session State
    @Published public var isLoggedIn: Bool = false
    @Published public var isLoading: Bool = false
    @Published public var errorMessage: String? = nil

    @Published public var baseUrl: String = UserDefaults.standard.string(forKey: "aipbx_base_url") ?? "http://10.8.0.10"
    @Published public var savedUsername: String = UserDefaults.standard.string(forKey: "aipbx_username") ?? ""
    @Published public var rememberMe: Bool = UserDefaults.standard.bool(forKey: "aipbx_remember_me")

    @Published public var userProfile: UserProfile? = nil
    @Published public var token: String? = nil
    @Published public var sipCredentials: SipCredentials? = nil

    // MARK: - Engine & Call State
    @Published public var connectionStatus: ConnectionStatus = .disconnected
    @Published public var callStatus: CallStatus = .idle
    @Published public var activeCallerName: String = ""
    @Published public var activeCallerNumber: String = ""
    @Published public var isSpeakerOn: Bool = false
    @Published public var isMuted: Bool = false
    @Published public var isCallSheetPresented: Bool = false

    // MARK: - Tab Data
    @Published public var callHistory: [CallRecord] = []
    @Published public var contacts: [ContactItem] = []
    @Published public var conversations: [ChatConversation] = []
    @Published public var features: FeatureSettings? = nil
    @Published public var activeConversationId: Int? = nil

    private var cancellables = Set<AnyCancellable>()

    private init() {
        SipWebRtcEngine.shared.delegate = self
        ChatWebSocketManager.shared.delegate = self

        AudioSessionManager.shared.$isSpeakerOn
            .receive(on: DispatchQueue.main)
            .assign(to: \.isSpeakerOn, on: self)
            .store(in: &cancellables)

        AudioSessionManager.shared.$isMuted
            .receive(on: DispatchQueue.main)
            .assign(to: \.isMuted, on: self)
            .store(in: &cancellables)

        CallKitManager.shared.onAnswerCall = { [weak self] in
            Task { @MainActor in
                self?.answerCall()
            }
        }

        CallKitManager.shared.onEndCall = { [weak self] in
            Task { @MainActor in
                self?.endCall()
            }
        }
    }

    // MARK: - Login & Logout

    public func login(serverUrl: String, username: String, pass: String) async -> Bool {
        isLoading = true
        errorMessage = nil

        do {
            let res = try await ApiClient.shared.login(baseUrl: serverUrl, userOrExt: username, pass: pass)
            self.baseUrl = serverUrl
            self.token = res.token
            self.userProfile = res.user
            self.sipCredentials = res.sip
            self.isLoggedIn = true
            self.isLoading = false

            UserDefaults.standard.set(serverUrl, forKey: "aipbx_base_url")
            if rememberMe {
                UserDefaults.standard.set(username, forKey: "aipbx_username")
                UserDefaults.standard.set(true, forKey: "aipbx_remember_me")
            } else {
                UserDefaults.standard.removeObject(forKey: "aipbx_username")
                UserDefaults.standard.set(false, forKey: "aipbx_remember_me")
            }

            // Register SIP WebRTC
            if let sip = res.sip {
                let dName = res.user?.fullName ?? res.user?.username ?? sip.sipUsername
                SipWebRtcEngine.shared.register(sip: sip, displayName: dName)
            }

            // Connect Chat WebSocket
            if let tok = res.token {
                ChatWebSocketManager.shared.connect(baseUrl: serverUrl, token: tok)
            }

            // Refresh initial data
            await refreshAllData()
            return true
        } catch {
            self.isLoading = false
            self.errorMessage = error.localizedDescription
            return false
        }
    }

    public func logout() {
        SipWebRtcEngine.shared.unregister()
        ChatWebSocketManager.shared.disconnect()

        isLoggedIn = false
        token = nil
        userProfile = nil
        sipCredentials = nil
        callHistory.removeAll()
        contacts.removeAll()
        conversations.removeAll()
        features = nil
        callStatus = .idle
        isCallSheetPresented = false
    }

    // MARK: - Data Synchronization

    public func refreshAllData() async {
        guard let token = token else { return }

        async let histTask = ApiClient.shared.getCallHistory(baseUrl: baseUrl, token: token)
        async let contactsTask = ApiClient.shared.getContacts(baseUrl: baseUrl, token: token)
        async let convsTask = ApiClient.shared.getChatConversations(baseUrl: baseUrl, token: token)
        async let featTask = ApiClient.shared.getFeatures(baseUrl: baseUrl, token: token)

        if let historyRes = try? await histTask {
            self.callHistory = historyRes.calls ?? []
        }
        if let contactsRes = try? await contactsTask {
            self.contacts = contactsRes
        }
        if let convsRes = try? await convsTask {
            self.conversations = convsRes
        }
        if let featRes = try? await featTask {
            self.features = featRes
        }
    }

    public func updateFeatures(_ settings: FeatureSettings) async {
        guard let token = token else { return }
        do {
            let success = try await ApiClient.shared.updateFeatures(baseUrl: baseUrl, token: token, settings: settings)
            if success {
                self.features = settings
                AppLogManager.shared.info("AppState", "Features updated successfully")
            }
        } catch {
            AppLogManager.shared.error("AppState", "Error updating features: \(error.localizedDescription)")
        }
    }

    // MARK: - Call Handling

    public func makeCall(to target: String) {
        guard !target.isEmpty else { return }
        self.activeCallerName = target
        self.activeCallerNumber = target
        self.isCallSheetPresented = true
        SipWebRtcEngine.shared.makeCall(target: target)
    }

    public func answerCall() {
        SipWebRtcEngine.shared.answerCall()
    }

    public func endCall() {
        SipWebRtcEngine.shared.terminateCall()
        DispatchQueue.main.asyncAfter(deadline: .now() + 1.0) {
            self.isCallSheetPresented = false
        }
    }

    public func toggleSpeaker() {
        AudioSessionManager.shared.setSpeaker(enabled: !isSpeakerOn)
    }

    public func toggleMute() {
        SipWebRtcEngine.shared.toggleMute()
    }

    public func toggleHold() {
        SipWebRtcEngine.shared.toggleHold()
    }

    public func sendDTMF(_ digit: String) {
        SipWebRtcEngine.shared.sendDTMF(digit)
    }

    // MARK: - SipWebRtcEngineDelegate

    nonisolated public func engineDidUpdateConnectionStatus(_ status: ConnectionStatus) {
        Task { @MainActor in
            self.connectionStatus = status
        }
    }

    nonisolated public func engineDidUpdateCallStatus(_ status: CallStatus) {
        Task { @MainActor in
            self.callStatus = status
            if status == .ringingIncoming || status == .connecting || status == .active || status == .onHold {
                self.isCallSheetPresented = true
            } else if status == .ended || status == .idle {
                DispatchQueue.main.asyncAfter(deadline: .now() + 1.2) {
                    if self.callStatus == .idle || self.callStatus == .ended {
                        self.isCallSheetPresented = false
                    }
                }
            }
        }
    }

    nonisolated public func engineDidReceiveIncomingCall(callerName: String, callerNumber: String) {
        Task { @MainActor in
            self.activeCallerName = callerName
            self.activeCallerNumber = callerNumber
            self.isCallSheetPresented = true
        }
    }

    // MARK: - ChatWebSocketDelegate

    nonisolated public func webSocketDidUpdateConnectionState(_ isConnected: Bool) {
        Task { @MainActor in
            AppLogManager.shared.info("AppState", "WebSocket state: \(isConnected ? "Connected" : "Disconnected")")
        }
    }

    nonisolated public func webSocketDidReceiveNewMessage(_ message: ChatMessage) {
        Task { @MainActor in
            if let index = self.conversations.firstIndex(where: { $0.id == message.conversationId }) {
                self.conversations[index].lastMessageText = message.message
                self.conversations[index].lastMessageAt = message.createdAt
                if self.activeConversationId != message.conversationId && !(message.isMe ?? false) {
                    self.conversations[index].unreadCount += 1
                }
            }
        }
    }

    nonisolated public func webSocketDidReceivePresence(extensionNumber: String, isOnline: Bool) {
        Task { @MainActor in
            if let idx = self.contacts.firstIndex(where: { $0.extensionNumber == extensionNumber }) {
                self.contacts[idx].status = isOnline ? "online" : "offline"
            }
        }
    }

    nonisolated public func webSocketDidReceiveTyping(conversationId: Int, senderName: String, isTyping: Bool) {
        // Handled in ChatRoomView
    }
}
