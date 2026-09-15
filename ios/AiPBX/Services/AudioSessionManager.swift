import Foundation
import AVFoundation

public final class AudioSessionManager: NSObject, ObservableObject {
    public static let shared = AudioSessionManager()

    @Published public private(set) var isSpeakerOn: Bool = false
    @Published public private(set) var isMuted: Bool = false

    private override init() {
        super.init()
    }

    public func configureAudioSessionForCall() {
        let session = AVAudioSession.sharedInstance()
        do {
            try session.setCategory(.playAndRecord, mode: .voiceChat, options: [.allowBluetooth, .allowBluetoothA2DP])
            try session.setActive(true)
            AppLogManager.shared.info("AudioSession", "AVAudioSession configured for voice call")
        } catch {
            AppLogManager.shared.error("AudioSession", "Failed to configure audio session: \(error.localizedDescription)")
        }
    }

    public func endAudioSession() {
        let session = AVAudioSession.sharedInstance()
        do {
            try session.overrideOutputAudioPort(.none)
            try session.setActive(false, options: .notifyOthersOnDeactivation)
            isSpeakerOn = false
            isMuted = false
            AppLogManager.shared.info("AudioSession", "AVAudioSession deactivated")
        } catch {
            AppLogManager.shared.warn("AudioSession", "Error deactivating audio session: \(error.localizedDescription)")
        }
    }

    public func setSpeaker(enabled: Bool) {
        let session = AVAudioSession.sharedInstance()
        do {
            if enabled {
                try session.overrideOutputAudioPort(.speaker)
            } else {
                try session.overrideOutputAudioPort(.none)
            }
            self.isSpeakerOn = enabled
            AppLogManager.shared.info("AudioSession", "Speaker toggled: \(enabled)")
        } catch {
            AppLogManager.shared.error("AudioSession", "Failed to toggle speaker: \(error.localizedDescription)")
        }
    }

    public func setMuted(enabled: Bool) {
        self.isMuted = enabled
        AppLogManager.shared.info("AudioSession", "Mute toggled: \(enabled)")
    }
}
