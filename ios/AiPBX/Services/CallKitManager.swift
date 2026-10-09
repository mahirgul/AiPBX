import Foundation
import CallKit
import AVFoundation

public final class CallKitManager: NSObject, CXProviderDelegate {
    public static let shared = CallKitManager()

    private let provider: CXProvider
    private let callController = CXCallController()
    private var currentCallUUID: UUID?

    /// A call reported from a VoIP push whose SIP INVITE has not arrived yet.
    private var pushCallUUID: UUID?
    private var pushCallAnswered = false
    private var pushCallTimeout: DispatchWorkItem?
    /// The user declined a pushed call before its INVITE arrived: the INVITE is rejected.
    private var pushCallDeclinedUntil: Date?
    /// How long a pushed call waits for the INVITE (the PBX waits up to 30 s for the app).
    private static let pushCallWait: TimeInterval = 35

    /// What the SIP engine should do with an INVITE.
    public enum IncomingCallDecision {
        /// Ring (the CallKit call is already showing or was just reported).
        case ring
        /// The user already answered the pushed call: answer the INVITE at once.
        case answer
        /// The user already declined the pushed call: reject the INVITE.
        case reject
    }

    public var onAnswerCall: (() -> Void)?
    public var onEndCall: (() -> Void)?
    public var onSetMute: ((Bool) -> Void)?

    private override init() {
        let configuration = CXProviderConfiguration()
        configuration.supportsVideo = false
        configuration.maximumCallsPerCallGroup = 1
        configuration.supportedHandleTypes = [.generic]

        self.provider = CXProvider(configuration: configuration)
        super.init()
        self.provider.setDelegate(self, queue: nil)
    }

    public func reportIncomingCall(uuid: UUID, callerName: String, callerNumber: String,
                                   keepAsCurrent: Bool = true, completion: ((Error?) -> Void)? = nil) {
        if keepAsCurrent {
            currentCallUUID = uuid
        }
        let update = CXCallUpdate()
        update.remoteHandle = CXHandle(type: .generic, value: callerNumber)
        update.localizedCallerName = callerName
        update.hasVideo = false
        update.supportsDTMF = true
        update.supportsHolding = true
        update.supportsGrouping = false
        update.supportsUngrouping = false

        provider.reportNewIncomingCall(with: uuid, update: update) { error in
            if let error = error {
                AppLogManager.shared.error("CallKit", "Failed to report incoming call: \(error.localizedDescription)")
            } else {
                AppLogManager.shared.info("CallKit", "Reported incoming call for \(callerName) (\(callerNumber))")
            }
            completion?(error)
        }
    }

    /// Reports the call a VoIP push announced. iOS requires this for every
    /// VoIP push, even when the app is already busy with a call.
    public func reportPushedIncomingCall(callerName: String, callerNumber: String, completion: @escaping (Error?) -> Void) {
        let uuid = UUID()
        if currentCallUUID != nil {
            // Busy: the extra call is shown and ended at once; the PBX handles it (call waiting/busy).
            reportIncomingCall(uuid: uuid, callerName: callerName, callerNumber: callerNumber, keepAsCurrent: false) { [weak self] error in
                self?.provider.reportCall(with: uuid, endedAt: Date(), reason: .unanswered)
                completion(error)
            }
            return
        }

        pushCallUUID = uuid
        pushCallAnswered = false
        pushCallDeclinedUntil = nil
        reportIncomingCall(uuid: uuid, callerName: callerName, callerNumber: callerNumber) { [weak self] error in
            if error != nil {
                self?.clearPushCall()
            }
            completion(error)
        }

        let timeout = DispatchWorkItem { [weak self] in
            guard let self = self, self.pushCallUUID == uuid else { return }
            AppLogManager.shared.warn("CallKit", "The pushed call's INVITE did not arrive; ending it")
            self.provider.reportCall(with: uuid, endedAt: Date(), reason: .failed)
            if self.currentCallUUID == uuid { self.currentCallUUID = nil }
            self.clearPushCall()
            AudioSessionManager.shared.endAudioSession()
        }
        pushCallTimeout = timeout
        DispatchQueue.main.asyncAfter(deadline: .now() + Self.pushCallWait, execute: timeout)
    }

    /// Called by the SIP engine for every INVITE. A call announced by a push
    /// keeps its CallKit entry (no second ringing screen); any other call is
    /// reported as new.
    public func handleIncomingInvite(callerName: String, callerNumber: String) -> IncomingCallDecision {
        if let until = pushCallDeclinedUntil, until > Date() {
            pushCallDeclinedUntil = nil
            return .reject
        }
        pushCallDeclinedUntil = nil

        guard let uuid = pushCallUUID else {
            reportIncomingCall(uuid: UUID(), callerName: callerName, callerNumber: callerNumber)
            return .ring
        }

        let answered = pushCallAnswered
        clearPushCall()
        currentCallUUID = uuid
        let update = CXCallUpdate()
        update.remoteHandle = CXHandle(type: .generic, value: callerNumber)
        update.localizedCallerName = callerName
        provider.reportCall(with: uuid, updated: update)
        return answered ? .answer : .ring
    }

    /// The pushed call cannot be delivered (no saved session): stop ringing now.
    public func failPushedCall() {
        guard let uuid = pushCallUUID else { return }
        provider.reportCall(with: uuid, endedAt: Date(), reason: .failed)
        if currentCallUUID == uuid { currentCallUUID = nil }
        clearPushCall()
    }

    private func clearPushCall() {
        pushCallTimeout?.cancel()
        pushCallTimeout = nil
        pushCallUUID = nil
        pushCallAnswered = false
    }

    public func startOutgoingCall(uuid: UUID, handle: String) {
        currentCallUUID = uuid
        let handleObj = CXHandle(type: .generic, value: handle)
        let action = CXStartCallAction(call: uuid, handle: handleObj)
        let transaction = CXTransaction(action: action)

        callController.request(transaction) { error in
            if let error = error {
                AppLogManager.shared.error("CallKit", "Failed to start outgoing call: \(error.localizedDescription)")
            } else {
                AppLogManager.shared.info("CallKit", "Started outgoing call to \(handle)")
            }
        }
    }

    public func reportCallConnected() {
        guard let uuid = currentCallUUID else { return }
        provider.reportOutgoingCall(with: uuid, connectedAt: Date())
    }

    public func endCall() {
        guard let uuid = currentCallUUID else { return }
        let endCallAction = CXEndCallAction(call: uuid)
        let transaction = CXTransaction(action: endCallAction)

        callController.request(transaction) { error in
            if let error = error {
                AppLogManager.shared.warn("CallKit", "Request end call error: \(error.localizedDescription)")
            }
        }
        currentCallUUID = nil
    }

    public func reportCallEndedExternally(reason: CXCallEndedReason = .remoteEnded) {
        guard let uuid = currentCallUUID else { return }
        provider.reportCall(with: uuid, endedAt: Date(), reason: reason)
        currentCallUUID = nil
    }

    // MARK: - CXProviderDelegate

    public func providerDidReset(_ provider: CXProvider) {
        AppLogManager.shared.info("CallKit", "Provider did reset")
        AudioSessionManager.shared.endAudioSession()
        currentCallUUID = nil
        clearPushCall()
    }

    public func provider(_ provider: CXProvider, perform action: CXAnswerCallAction) {
        AppLogManager.shared.info("CallKit", "User answered call via CallKit")
        AudioSessionManager.shared.configureAudioSessionForCall()
        if action.callUUID == pushCallUUID {
            // The INVITE is still on its way: it is answered when it arrives.
            pushCallAnswered = true
        } else {
            onAnswerCall?()
        }
        action.fulfill()
    }

    public func provider(_ provider: CXProvider, perform action: CXEndCallAction) {
        AppLogManager.shared.info("CallKit", "User ended call via CallKit")
        AudioSessionManager.shared.endAudioSession()
        if action.callUUID == pushCallUUID {
            // Declined before the INVITE arrived: reject it when it does.
            pushCallDeclinedUntil = Date().addingTimeInterval(Self.pushCallWait)
            clearPushCall()
        } else {
            onEndCall?()
        }
        currentCallUUID = nil
        action.fulfill()
    }

    public func provider(_ provider: CXProvider, perform action: CXSetMutedCallAction) {
        AppLogManager.shared.info("CallKit", "User toggled mute: \(action.isMuted)")
        AudioSessionManager.shared.setMuted(enabled: action.isMuted)
        onSetMute?(action.isMuted)
        action.fulfill()
    }

    public func provider(_ provider: CXProvider, didActivate audioSession: AVAudioSession) {
        AppLogManager.shared.info("CallKit", "Audio session activated by CallKit")
        AudioSessionManager.shared.configureAudioSessionForCall()
    }

    public func provider(_ provider: CXProvider, didDeactivate audioSession: AVAudioSession) {
        AppLogManager.shared.info("CallKit", "Audio session deactivated by CallKit")
        AudioSessionManager.shared.endAudioSession()
    }
}
