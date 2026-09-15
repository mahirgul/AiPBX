import Foundation
import CallKit
import AVFoundation

public final class CallKitManager: NSObject, CXProviderDelegate {
    public static let shared = CallKitManager()

    private let provider: CXProvider
    private let callController = CXCallController()
    private var currentCallUUID: UUID?

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

    public func reportIncomingCall(uuid: UUID, callerName: String, callerNumber: String, completion: ((Error?) -> Void)? = nil) {
        currentCallUUID = uuid
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
    }

    public func provider(_ provider: CXProvider, perform action: CXAnswerCallAction) {
        AppLogManager.shared.info("CallKit", "User answered call via CallKit")
        AudioSessionManager.shared.configureAudioSessionForCall()
        onAnswerCall?()
        action.fulfill()
    }

    public func provider(_ provider: CXProvider, perform action: CXEndCallAction) {
        AppLogManager.shared.info("CallKit", "User ended call via CallKit")
        AudioSessionManager.shared.endAudioSession()
        onEndCall?()
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
