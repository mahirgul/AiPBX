import SwiftUI
import WebKit

private struct WebRtcVideoContainerView: UIViewRepresentable {
    func makeUIView(context: Context) -> UIView {
        let container = UIView()
        container.backgroundColor = .black
        if let wv = SipWebRtcEngine.shared.webView {
            wv.translatesAutoresizingMaskIntoConstraints = false
            container.addSubview(wv)
            NSLayoutConstraint.activate([
                wv.leadingAnchor.constraint(equalTo: container.leadingAnchor),
                wv.trailingAnchor.constraint(equalTo: container.trailingAnchor),
                wv.topAnchor.constraint(equalTo: container.topAnchor),
                wv.bottomAnchor.constraint(equalTo: container.bottomAnchor)
            ])
        }
        return container
    }

    func updateUIView(_ uiView: UIView, context: Context) {}
}

public struct ActiveCallView: View {
    @EnvironmentObject private var appState: AppState
    @ObservedObject private var sipEngine = SipWebRtcEngine.shared
    @State private var callDurationSeconds: Int = 0
    @State private var timer: Timer? = nil
    @State private var showDtmfKeypad: Bool = false
    @State private var showTransferAlert: Bool = false
    @State private var transferTarget: String = ""

    public init() {}

    public var body: some View {
        ZStack {
            // Dark gradient backdrop or active WebRTC video view
            LinearGradient(
                colors: [Color(red: 0.08, green: 0.12, blue: 0.20), Color(red: 0.02, green: 0.04, blue: 0.08)],
                startPoint: .top,
                endPoint: .bottom
            )
            .ignoresSafeArea()

            if sipEngine.isVideoActive {
                WebRtcVideoContainerView()
                    .ignoresSafeArea()
            }

            VStack(spacing: 32) {
                Spacer(minLength: 20)

                // Caller Info Header
                VStack(spacing: 12) {
                    if !sipEngine.isVideoActive {
                        AvatarView(name: appState.activeCallerName.isEmpty ? L("PBX") : appState.activeCallerName, size: 96)
                            .overlay(
                                Circle()
                                    .stroke(Color.white.opacity(0.15), lineWidth: 2)
                            )
                    }

                    Text(appState.activeCallerName.isEmpty ? L("PBX") : appState.activeCallerName)
                        .font(.system(size: 26, weight: .bold))
                        .foregroundColor(.white)

                    if !appState.activeCallerNumber.isEmpty && appState.activeCallerNumber != appState.activeCallerName {
                        Text(appState.activeCallerNumber)
                            .font(.subheadline)
                            .foregroundColor(.white.opacity(0.7))
                    }

                    // Status / Timer
                    Text(callStatusDisplay)
                        .font(.headline)
                        .foregroundColor(statusDisplayColor)
                        .padding(.top, 4)
                }

                Spacer()

                if showDtmfKeypad {
                    // In-call DTMF Keypad
                    VStack(spacing: 12) {
                        DialKeypadView { digit in
                            appState.sendDTMF(digit)
                        }

                        Button(action: { showDtmfKeypad = false }) {
                            Text(L("Hide Keypad"))
                                .font(.footnote.bold())
                                .foregroundColor(.white)
                                .padding(.horizontal, 16)
                                .padding(.vertical, 8)
                                .background(Color.white.opacity(0.2))
                                .cornerRadius(20)
                        }
                    }
                    .transition(.scale.combined(with: .opacity))
                } else {
                    // Call Action Buttons (Mute, DTMF, Speaker, Hold, Transfer, Camera)
                    if appState.callStatus != .ringingIncoming {
                        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible()), GridItem(.flexible())], spacing: 24) {
                            CallActionButton(
                                icon: appState.isMuted ? "mic.slash.fill" : "mic.fill",
                                title: appState.isMuted ? L("Muted") : L("Mute"),
                                isActive: appState.isMuted
                            ) {
                                appState.toggleMute()
                            }

                            CallActionButton(
                                icon: "circle.grid.3x3.fill",
                                title: L("Keypad"),
                                isActive: showDtmfKeypad
                            ) {
                                withAnimation {
                                    showDtmfKeypad.toggle()
                                }
                            }

                            CallActionButton(
                                icon: appState.isSpeakerOn ? "speaker.wave.3.fill" : "speaker.fill",
                                title: appState.isSpeakerOn ? L("Speaker On") : L("Speaker"),
                                isActive: appState.isSpeakerOn
                            ) {
                                appState.toggleSpeaker()
                            }

                            CallActionButton(
                                icon: appState.callStatus == .onHold ? "play.fill" : "pause.fill",
                                title: appState.callStatus == .onHold ? L("Resume") : L("Hold"),
                                isActive: appState.callStatus == .onHold
                            ) {
                                appState.toggleHold()
                            }

                            CallActionButton(
                                icon: "arrowshape.turn.up.right.fill",
                                title: L("Transfer"),
                                isActive: false
                            ) {
                                transferTarget = ""
                                showTransferAlert = true
                            }

                            if sipEngine.isVideoActive {
                                CallActionButton(
                                    icon: sipEngine.isCameraEnabled ? "video.fill" : "video.slash.fill",
                                    title: L("Camera"),
                                    isActive: !sipEngine.isCameraEnabled
                                ) {
                                    appState.toggleCamera()
                                }

                                CallActionButton(
                                    icon: "arrow.triangle.2.circlepath.camera.fill",
                                    title: L("Switch camera"),
                                    isActive: false
                                ) {
                                    appState.switchCamera()
                                }
                            }
                        }
                        .padding(.horizontal, 28)
                    }
                }

                Spacer()

                // Bottom Call Controls (Accept / Decline / Hangup)
                if appState.callStatus == .ringingIncoming {
                    HStack(spacing: 40) {
                        // Decline Button
                        Button(action: {
                            appState.endCall()
                        }) {
                            VStack(spacing: 8) {
                                ZStack {
                                    Circle()
                                        .fill(Color.red)
                                        .frame(width: 72, height: 72)
                                    Image(systemName: "phone.down.fill")
                                        .font(.title)
                                        .foregroundColor(.white)
                                }
                                Text(L("Decline"))
                                    .font(.footnote.bold())
                                    .foregroundColor(.white)
                            }
                        }

                        // Accept Button (Audio)
                        Button(action: {
                            appState.answerCall(withVideo: false)
                        }) {
                            VStack(spacing: 8) {
                                ZStack {
                                    Circle()
                                        .fill(Color.green)
                                        .frame(width: 72, height: 72)
                                    Image(systemName: "phone.fill")
                                        .font(.title)
                                        .foregroundColor(.white)
                                }
                                Text(L("Answer"))
                                    .font(.footnote.bold())
                                    .foregroundColor(.white)
                            }
                        }

                        // Accept Video Button (if incoming offer has video)
                        if sipEngine.incomingHasVideo && appState.isVideoCallsEnabled {
                            Button(action: {
                                appState.answerCall(withVideo: true)
                            }) {
                                VStack(spacing: 8) {
                                    ZStack {
                                        Circle()
                                            .fill(Color.blue)
                                            .frame(width: 72, height: 72)
                                        Image(systemName: "video.fill")
                                            .font(.title)
                                            .foregroundColor(.white)
                                    }
                                    Text(L("Video"))
                                        .font(.footnote.bold())
                                        .foregroundColor(.white)
                                }
                            }
                        }
                    }
                    .padding(.bottom, 40)
                } else {
                    // Hangup Button
                    Button(action: {
                        appState.endCall()
                    }) {
                        ZStack {
                            Circle()
                                .fill(Color.red)
                                .frame(width: 76, height: 76)
                                .shadow(color: .red.opacity(0.4), radius: 8, y: 4)

                            Image(systemName: "phone.down.fill")
                                .font(.title)
                                .foregroundColor(.white)
                        }
                    }
                    .padding(.bottom, 40)
                }
            }
        }
        .onAppear {
            startDurationTimer()
        }
        .onDisappear {
            stopDurationTimer()
        }
        .onChange(of: appState.callStatus) { newStatus in
            if newStatus == .active && timer == nil {
                startDurationTimer()
            } else if newStatus == .ended || newStatus == .idle {
                stopDurationTimer()
            }
        }
        .alert(L("Transfer Call"), isPresented: $showTransferAlert) {
            TextField(L("e.g. 1002 or 9998"), text: $transferTarget)
                .keyboardType(.phonePad)
            Button(L("Transfer")) {
                let target = transferTarget.trimmingCharacters(in: .whitespacesAndNewlines)
                if !target.isEmpty {
                    appState.transferCall(to: target)
                }
            }
            Button(L("Cancel"), role: .cancel) {}
        } message: {
            Text(L("Enter the extension to transfer to:"))
        }
    }

    private var callStatusDisplay: String {
        switch appState.callStatus {
        case .idle: return L("No Call")
        case .connecting: return L("Calling...")
        case .ringingOutgoing: return L("Ringing...")
        case .ringingIncoming: return L("Incoming Call...")
        case .onHold: return L("On hold")
        case .ended: return L("Call Ended")
        case .active:
            let min = callDurationSeconds / 60
            let sec = callDurationSeconds % 60
            return String(format: "%02d:%02d", min, sec)
        }
    }

    private var statusDisplayColor: Color {
        switch appState.callStatus {
        case .active: return .green
        case .onHold: return .orange
        case .ringingIncoming, .ringingOutgoing: return .cyan
        case .ended: return .red
        default: return .white.opacity(0.7)
        }
    }

    private func startDurationTimer() {
        if appState.callStatus == .active {
            callDurationSeconds = 0
            timer?.invalidate()
            timer = Timer.scheduledTimer(withTimeInterval: 1.0, repeats: true) { _ in
                self.callDurationSeconds += 1
            }
        }
    }

    private func stopDurationTimer() {
        timer?.invalidate()
        timer = nil
    }
}

public struct CallActionButton: View {
    public let icon: String
    public let title: String
    public let isActive: Bool
    public let action: () -> Void

    public var body: some View {
        Button(action: action) {
            VStack(spacing: 8) {
                ZStack {
                    Circle()
                        .fill(isActive ? Color.white : Color.white.opacity(0.15))
                        .frame(width: 60, height: 60)

                    Image(systemName: icon)
                        .font(.system(size: 22))
                        .foregroundColor(isActive ? Color.black : Color.white)
                }

                Text(title)
                    .font(.caption)
                    .foregroundColor(.white.opacity(0.8))
            }
        }
    }
}
