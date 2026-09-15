import SwiftUI

public struct ActiveCallView: View {
    @EnvironmentObject private var appState: AppState
    @State private var callDurationSeconds: Int = 0
    @State private var timer: Timer? = nil
    @State private var showDtmfKeypad: Bool = false

    public init() {}

    public var body: some View {
        ZStack {
            // Dark gradient backdrop
            LinearGradient(
                colors: [Color(red: 0.08, green: 0.12, blue: 0.20), Color(red: 0.02, green: 0.04, blue: 0.08)],
                startPoint: .top,
                endPoint: .bottom
            )
            .ignoresSafeArea()

            VStack(spacing: 32) {
                Spacer(minLength: 20)

                // Caller Info Header
                VStack(spacing: 12) {
                    AvatarView(name: appState.activeCallerName.isEmpty ? "Santral" : appState.activeCallerName, size: 96)
                        .overlay(
                            Circle()
                                .stroke(Color.white.opacity(0.15), lineWidth: 2)
                        )

                    Text(appState.activeCallerName.isEmpty ? "Santral" : appState.activeCallerName)
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
                            Text("Klavyeyi Kapat")
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
                    // Call Action Buttons (Mute, DTMF, Speaker, Hold)
                    if appState.callStatus != .ringingIncoming {
                        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 24) {
                            CallActionButton(
                                icon: appState.isMuted ? "mic.slash.fill" : "mic.fill",
                                title: appState.isMuted ? "Sessiz Açık" : "Sessiz",
                                isActive: appState.isMuted
                            ) {
                                appState.toggleMute()
                            }

                            CallActionButton(
                                icon: "circle.grid.3x3.fill",
                                title: "Tuş Takımı",
                                isActive: showDtmfKeypad
                            ) {
                                withAnimation {
                                    showDtmfKeypad.toggle()
                                }
                            }

                            CallActionButton(
                                icon: appState.isSpeakerOn ? "speaker.wave.3.fill" : "speaker.fill",
                                title: appState.isSpeakerOn ? "Hoparlör Açık" : "Hoparlör",
                                isActive: appState.isSpeakerOn
                            ) {
                                appState.toggleSpeaker()
                            }

                            CallActionButton(
                                icon: appState.callStatus == .onHold ? "play.fill" : "pause.fill",
                                title: appState.callStatus == .onHold ? "Devam Et" : "Beklet",
                                isActive: appState.callStatus == .onHold
                            ) {
                                appState.toggleHold()
                            }
                        }
                        .padding(.horizontal, 48)
                    }
                }

                Spacer()

                // Bottom Call Controls (Accept / Decline / Hangup)
                if appState.callStatus == .ringingIncoming {
                    HStack(spacing: 60) {
                        // Decline Button
                        Button(action: {
                            appState.endCall()
                        }) {
                            VStack(spacing: 8) {
                                ZStack {
                                    Circle()
                                        .fill(Color.red)
                                        .frame(width: 76, height: 76)
                                    Image(systemName: "phone.down.fill")
                                        .font(.title)
                                        .foregroundColor(.white)
                                }
                                Text("Reddet")
                                    .font(.footnote.bold())
                                    .foregroundColor(.white)
                            }
                        }

                        // Accept Button
                        Button(action: {
                            appState.answerCall()
                        }) {
                            VStack(spacing: 8) {
                                ZStack {
                                    Circle()
                                        .fill(Color.green)
                                        .frame(width: 76, height: 76)
                                    Image(systemName: "phone.fill")
                                        .font(.title)
                                        .foregroundColor(.white)
                                }
                                Text("Cevapla")
                                    .font(.footnote.bold())
                                    .foregroundColor(.white)
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
    }

    private var callStatusDisplay: String {
        switch appState.callStatus {
        case .idle: return "Görüşme Yok"
        case .connecting: return "Aranıyor..."
        case .ringingOutgoing: return "Çalıyor..."
        case .ringingIncoming: return "Gelen Çağrı..."
        case .onHold: return "Beklemede"
        case .ended: return "Görüşme Sonlandı"
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
