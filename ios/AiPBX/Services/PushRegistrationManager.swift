import Foundation
import PushKit
import UIKit

/// APNs (chat, test) and PushKit VoIP (incoming call) tokens, sent to the PBX
/// through api/mobile/fcm_token.php like Android's FCM token. A VoIP push for
/// an incoming call is reported to CallKit at once (iOS requires it) and the
/// SIP connection is brought back so the PBX can deliver the INVITE: the iOS
/// counterpart of Android's AiPbxFirebaseMessagingService.
public final class PushRegistrationManager: NSObject, PKPushRegistryDelegate {
    public static let shared = PushRegistrationManager()

    private var voipRegistry: PKPushRegistry?
    private var apnsToken: String?
    private var voipToken: String?
    /// The token pair last accepted by the server, so a relaunch does not re-send it.
    private var lastSent: String?

    private override init() {
        super.init()
    }

    /// Stable per-install id; Android sends its own device id the same way.
    @MainActor
    public var deviceId: String {
        UIDevice.current.identifierForVendor?.uuidString ?? "ios-unknown"
    }

    /// Called at launch: PushKit must be registered early so a VoIP push that
    /// launched the app is delivered.
    public func start() {
        guard voipRegistry == nil else { return }
        let registry = PKPushRegistry(queue: .main)
        registry.delegate = self
        registry.desiredPushTypes = [.voIP]
        voipRegistry = registry
    }

    public func didReceiveApnsToken(_ data: Data) {
        apnsToken = data.map { String(format: "%02.2hhx", $0) }.joined()
        AppLogManager.shared.info("Push", "APNs token received")
        uploadIfPossible()
    }

    /// Sends the tokens when signed in. Called on sign-in and whenever a token changes.
    public func uploadIfPossible() {
        Task { @MainActor in
            let state = AppState.shared
            guard state.isLoggedIn, let token = state.token, !token.isEmpty else { return }
            guard apnsToken != nil || voipToken != nil else { return }

            let key = "\(state.baseUrl)|\(token)|\(apnsToken ?? "")|\(voipToken ?? "")"
            if key == lastSent { return }

            let version = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? ""
            do {
                try await ApiClient.shared.registerPushDevice(
                    baseUrl: state.baseUrl, token: token, deviceId: deviceId,
                    apnsToken: apnsToken, voipToken: voipToken,
                    deviceName: UIDevice.current.name, appVersion: version)
                lastSent = key
                AppLogManager.shared.info("Push", "Push tokens registered with the PBX")
            } catch {
                AppLogManager.shared.warn("Push", "Could not register push tokens: \(error.localizedDescription)")
            }
        }
    }

    /// Sign-out: the PBX stops pushing to this iPhone.
    @MainActor
    public func unregister(baseUrl: String, token: String) {
        lastSent = nil
        let id = deviceId
        Task {
            try? await ApiClient.shared.unregisterPushDevice(baseUrl: baseUrl, token: token, deviceId: id)
        }
    }

    // MARK: - PKPushRegistryDelegate

    public func pushRegistry(_ registry: PKPushRegistry, didUpdate pushCredentials: PKPushCredentials, for type: PKPushType) {
        guard type == .voIP else { return }
        voipToken = pushCredentials.token.map { String(format: "%02.2hhx", $0) }.joined()
        AppLogManager.shared.info("Push", "VoIP push token received")
        uploadIfPossible()
    }

    public func pushRegistry(_ registry: PKPushRegistry, didInvalidatePushTokenFor type: PKPushType) {
        guard type == .voIP else { return }
        voipToken = nil
        AppLogManager.shared.warn("Push", "VoIP push token invalidated")
    }

    public func pushRegistry(_ registry: PKPushRegistry, didReceiveIncomingPushWith payload: PKPushPayload,
                             for type: PKPushType, completion: @escaping () -> Void) {
        guard type == .voIP else {
            completion()
            return
        }
        let data = payload.dictionaryPayload
        let callerId = data["caller_id"] as? String ?? ""
        let callerName = (data["caller_name"] as? String).flatMap { $0.isEmpty ? nil : $0 } ?? callerId
        AppLogManager.shared.info("Push", "VoIP push: incoming call from \(callerId)")

        // iOS ends the app (and eventually stops VoIP pushes) if a VoIP push
        // is not reported to CallKit right away, so this happens before anything else.
        CallKitManager.shared.reportPushedIncomingCall(callerName: callerName, callerNumber: callerId) { _ in
            completion()
        }

        Task { @MainActor in
            await AppState.shared.wakeForIncomingCall()
        }
    }
}
