import Foundation
import WebKit
import Combine

public protocol SipWebRtcEngineDelegate: AnyObject {
    func engineDidUpdateConnectionStatus(_ status: ConnectionStatus)
    func engineDidUpdateCallStatus(_ status: CallStatus)
    func engineDidReceiveIncomingCall(callerName: String, callerNumber: String)
}

public final class SipWebRtcEngine: NSObject, ObservableObject, WKScriptMessageHandler, WKUIDelegate, WKNavigationDelegate {
    public static let shared = SipWebRtcEngine()

    @Published public private(set) var connectionStatus: ConnectionStatus = .disconnected
    @Published public private(set) var callStatus: CallStatus = .idle
    @Published public private(set) var activeCallerName: String = ""
    @Published public private(set) var activeCallerNumber: String = ""
    @Published public private(set) var isMuted: Bool = false
    @Published public private(set) var isHeld: Bool = false
    @Published public private(set) var isReady: Bool = false
    @Published public private(set) var incomingHasVideo: Bool = false
    @Published public private(set) var isVideoActive: Bool = false
    @Published public private(set) var isCameraEnabled: Bool = true

    public weak var delegate: SipWebRtcEngineDelegate?

    public private(set) var webView: WKWebView?
    private var pendingRegistration: (() -> Void)?
    private var lastSipCredentials: SipCredentials?
    private var lastDisplayName: String?

    private override init() {
        super.init()
        setupWebView()
    }

    private func setupWebView() {
        DispatchQueue.main.async { [weak self] in
            guard let self = self else { return }
            let config = WKWebViewConfiguration()
            config.allowsInlineMediaPlayback = true
            config.mediaTypesRequiringUserActionForPlayback = []

            let contentController = WKUserContentController()
            contentController.add(self, name: "iosBridge")
            config.userContentController = contentController

            let wv = WKWebView(frame: .zero, configuration: config)
            wv.uiDelegate = self
            wv.navigationDelegate = self
            self.webView = wv

            self.loadHtml()
        }
    }

    private func loadHtml() {
        guard let htmlUrl = Bundle.main.url(forResource: "phone_engine", withExtension: "html") else {
            AppLogManager.shared.error("SipEngine", "phone_engine.html not found in bundle")
            return
        }

        let accessUrl = htmlUrl.deletingLastPathComponent()
        webView?.loadFileURL(htmlUrl, allowingReadAccessTo: accessUrl)
        AppLogManager.shared.info("SipEngine", "Loading phone_engine.html...")
    }

    // MARK: - Public Controls

    public func register(sip: SipCredentials, displayName: String) {
        self.lastSipCredentials = sip
        self.lastDisplayName = displayName

        let action = { [weak self] in
            guard let self = self else { return }
            let turnUrl = sip.turn?.urls?.first ?? ""
            let turnUser = sip.turn?.username ?? ""
            let turnPass = sip.turn?.credential ?? ""

            let js = """
            initUA(
                '\(self.escapeForJs(sip.wsUrl))',
                '\(self.escapeForJs(sip.sipUsername))',
                '\(self.escapeForJs(sip.sipPassword))',
                '\(self.escapeForJs(sip.domain))',
                '\(self.escapeForJs(displayName))',
                '\(self.escapeForJs(turnUrl))',
                '\(self.escapeForJs(turnUser))',
                '\(self.escapeForJs(turnPass))'
            );
            """
            self.evaluateJs(js)
            AppLogManager.shared.info("SipEngine", "Register called for user: \(sip.sipUsername)")
        }

        if isReady {
            action()
        } else {
            pendingRegistration = action
        }
    }

    private func videoJsArg(_ video: VideoSettings?) -> String {
        guard let video = video, video.enabled else { return "null" }
        let parts = (video.resolution ?? "1280x720").split(separator: "x")
        let w = parts.count == 2 ? (Int(parts[0]) ?? 1280) : 1280
        let h = parts.count == 2 ? (Int(parts[1]) ?? 720) : 720
        let fps = video.fps ?? 24
        return "{w:\(w),h:\(h),fps:\(fps)}"
    }

    public func makeCall(target: String, video: VideoSettings? = nil) {
        guard let sip = lastSipCredentials else {
            AppLogManager.shared.warn("SipEngine", "Cannot make call: no active SIP credentials")
            return
        }

        AudioSessionManager.shared.configureAudioSessionForCall()
        let callUUID = UUID()
        CallKitManager.shared.startOutgoingCall(uuid: callUUID, handle: target)

        let turnUrl = sip.turn?.urls?.first ?? ""
        let turnUser = sip.turn?.username ?? ""
        let turnPass = sip.turn?.credential ?? ""
        let vArg = videoJsArg(video)

        let js = "makeCall('\(escapeForJs(target))', '\(escapeForJs(sip.domain))', '\(escapeForJs(turnUrl))', '\(escapeForJs(turnUser))', '\(escapeForJs(turnPass))', \(vArg));"
        evaluateJs(js)

        DispatchQueue.main.async {
            self.activeCallerName = target
            self.activeCallerNumber = target
            self.isCameraEnabled = true
            self.callStatus = .connecting
            self.delegate?.engineDidUpdateCallStatus(.connecting)
        }
        AppLogManager.shared.info("SipEngine", "Calling target: \(target)")
    }

    public func answerCall(video: VideoSettings? = nil) {
        AudioSessionManager.shared.configureAudioSessionForCall()
        let vArg = videoJsArg(video)
        evaluateJs("answerCall(\(vArg));")
        DispatchQueue.main.async {
            self.isCameraEnabled = true
            self.callStatus = .active
            self.delegate?.engineDidUpdateCallStatus(.active)
        }
        AppLogManager.shared.info("SipEngine", "Answering incoming call")
    }

    public func transferCall(target: String) {
        let domain = lastSipCredentials?.domain ?? ""
        evaluateJs("transferCall('\(escapeForJs(target))', '\(escapeForJs(domain))');")
        AppLogManager.shared.info("SipEngine", "Transferring call to: \(target)")
    }

    public func toggleCamera() {
        let next = !isCameraEnabled
        evaluateJs("toggleCamera(\(next ? "true" : "false"));")
        DispatchQueue.main.async {
            self.isCameraEnabled = next
        }
    }

    public func switchCamera() {
        evaluateJs("switchCamera();")
    }

    public func terminateCall() {
        evaluateJs("hangupCall();")
        CallKitManager.shared.endCall()
        AudioSessionManager.shared.endAudioSession()

        DispatchQueue.main.async {
            self.callStatus = .ended
            self.isMuted = false
            self.isHeld = false
            self.incomingHasVideo = false
            self.isVideoActive = false
            self.isCameraEnabled = true
            self.delegate?.engineDidUpdateCallStatus(.ended)
            DispatchQueue.main.asyncAfter(deadline: .now() + 1.0) {
                if self.callStatus == .ended {
                    self.callStatus = .idle
                    self.delegate?.engineDidUpdateCallStatus(.idle)
                }
            }
        }
        AppLogManager.shared.info("SipEngine", "Call terminated")
    }

    public func setMute(_ muted: Bool) {
        evaluateJs("toggleMute(\(muted ? "true" : "false"));")
        DispatchQueue.main.async {
            self.isMuted = muted
            AudioSessionManager.shared.setMuted(enabled: muted)
        }
    }

    public func toggleMute() {
        setMute(!isMuted)
    }

    public func setHold(_ held: Bool) {
        evaluateJs("toggleHold(\(held ? "true" : "false"));")
        DispatchQueue.main.async {
            self.isHeld = held
            if held {
                self.callStatus = .onHold
            } else {
                self.callStatus = .active
            }
            self.delegate?.engineDidUpdateCallStatus(self.callStatus)
        }
    }

    public func toggleHold() {
        setHold(!isHeld)
    }

    public func sendDTMF(_ tone: String) {
        let escaped = escapeForJs(tone)
        evaluateJs("sendDtmf('\(escaped)');")
        AppLogManager.shared.debug("SipEngine", "DTMF sent: \(tone)")
    }

    public func unregister() {
        evaluateJs("destroyUA();")
        DispatchQueue.main.async {
            self.connectionStatus = .disconnected
            self.delegate?.engineDidUpdateConnectionStatus(.disconnected)
        }
    }

    private func evaluateJs(_ script: String) {
        DispatchQueue.main.async { [weak self] in
            self?.webView?.evaluateJavaScript(script) { _, error in
                if let error = error {
                    AppLogManager.shared.error("SipEngine", "JS eval error: \(error.localizedDescription)")
                }
            }
        }
    }

    private func escapeForJs(_ string: String) -> String {
        return string
            .replacingOccurrences(of: "\\", with: "\\\\")
            .replacingOccurrences(of: "'", with: "\\'")
            .replacingOccurrences(of: "\n", with: "\\n")
            .replacingOccurrences(of: "\r", with: "")
            .replacingOccurrences(of: "\u{2028}", with: "\\u2028")
            .replacingOccurrences(of: "\u{2029}", with: "\\u2029")
    }

    // MARK: - WKScriptMessageHandler

    public func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        guard message.name == "iosBridge",
              let body = message.body as? [String: Any],
              let action = body["action"] as? String else {
            return
        }

        switch action {
        case "log":
            if let msg = body["message"] as? String {
                AppLogManager.shared.debug("WebRTC-JS", msg)
            }

        case "onEngineReady":
            AppLogManager.shared.info("SipEngine", "WebRTC engine is ready in WKWebView")
            DispatchQueue.main.async {
                self.isReady = true
                self.pendingRegistration?()
                self.pendingRegistration = nil
            }

        case "onStatusChanged":
            if let statusStr = body["status"] as? String {
                let status = ConnectionStatus(rawValue: statusStr) ?? .disconnected
                DispatchQueue.main.async {
                    self.connectionStatus = status
                    self.delegate?.engineDidUpdateConnectionStatus(status)
                }
                AppLogManager.shared.info("SipEngine", "SIP status: \(statusStr)")
            }

        case "onCallProgress":
            DispatchQueue.main.async {
                self.callStatus = .ringingOutgoing
                self.delegate?.engineDidUpdateCallStatus(.ringingOutgoing)
            }

        case "onCallConfirmed":
            AudioSessionManager.shared.configureAudioSessionForCall()
            CallKitManager.shared.reportCallConnected()
            DispatchQueue.main.async {
                self.callStatus = .active
                self.delegate?.engineDidUpdateCallStatus(.active)
            }

        case "onCallEnded":
            let cause = body["cause"] as? String ?? "Ended"
            CallKitManager.shared.reportCallEndedExternally()
            AudioSessionManager.shared.endAudioSession()
            DispatchQueue.main.async {
                self.callStatus = .ended
                self.isMuted = false
                self.isHeld = false
                self.incomingHasVideo = false
                self.isVideoActive = false
                self.isCameraEnabled = true
                self.delegate?.engineDidUpdateCallStatus(.ended)
                DispatchQueue.main.asyncAfter(deadline: .now() + 1.0) {
                    if self.callStatus == .ended {
                        self.callStatus = .idle
                        self.delegate?.engineDidUpdateCallStatus(.idle)
                    }
                }
            }
            AppLogManager.shared.info("SipEngine", "Call ended: \(cause)")

        case "setIncomingVideo":
            let hasVideo = body["hasVideo"] as? Bool ?? false
            DispatchQueue.main.async {
                self.incomingHasVideo = hasVideo
            }

        case "onVideoActive":
            let active = body["active"] as? Bool ?? false
            DispatchQueue.main.async {
                self.isVideoActive = active
                if active {
                    AudioSessionManager.shared.setSpeaker(enabled: true)
                }
            }

        case "onCameraUnavailable":
            AppLogManager.shared.warn("SipEngine", L("Camera not available: the call continues with audio only."))

        case "onIncomingCall":
            let number = body["number"] as? String ?? ""
            // The engine sends "" when the caller has no display name: show the number then.
            let rawName = (body["name"] as? String)?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
            let name = !rawName.isEmpty ? rawName : (!number.isEmpty ? number : L("Unknown Number"))
            DispatchQueue.main.async {
                self.activeCallerName = name
                self.activeCallerNumber = number
                self.callStatus = .ringingIncoming
                self.delegate?.engineDidReceiveIncomingCall(callerName: name, callerNumber: number)
                self.delegate?.engineDidUpdateCallStatus(.ringingIncoming)
            }
            AppLogManager.shared.info("SipEngine", "Incoming call: \(name) (\(number))")
            switch CallKitManager.shared.handleIncomingInvite(callerName: name, callerNumber: number) {
            case .ring:
                break
            case .answer:
                // Answered on the CallKit screen while the INVITE was on its way.
                answerCall()
            case .reject:
                evaluateJs("rejectCall();")
                DispatchQueue.main.async {
                    self.callStatus = .idle
                    self.delegate?.engineDidUpdateCallStatus(.idle)
                }
            }

        case "onRemoteAudioPlaying":
            AppLogManager.shared.info("SipEngine", "Remote audio playback confirmed active")

        case "onCallStatusChanged":
            if let status = body["status"] as? String {
                AppLogManager.shared.debug("SipEngine", "JS call status changed: \(status)")
            }

        default:
            break
        }
    }

    // MARK: - WKUIDelegate (WebRTC Permission)

    @available(iOS 15.0, *)
    public func webView(_ webView: WKWebView,
                        requestMediaCapturePermissionFor origin: WKSecurityOrigin,
                        initiatedByFrame frame: WKFrameInfo,
                        type: WKMediaCaptureType,
                        decisionHandler: @escaping (WKPermissionDecision) -> Void) {
        let proto = origin.protocol.lowercased()
        if origin.host.isEmpty || proto == "file" || proto == "about" {
            AppLogManager.shared.info("SipEngine", "Granting media capture permission for bundled WebRTC engine")
            decisionHandler(.grant)
        } else {
            AppLogManager.shared.warn("SipEngine", "Denied media capture permission for external origin: \(origin.host)")
            decisionHandler(.deny)
        }
    }

    public func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        AppLogManager.shared.debug("SipEngine", "WKWebView finished loading navigation")
    }

    public func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
        AppLogManager.shared.error("SipEngine", "WKWebView failed loading: \(error.localizedDescription)")
    }
}
