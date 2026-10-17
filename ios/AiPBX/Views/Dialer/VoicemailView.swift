import SwiftUI
import AVFoundation

public struct VoicemailView: View {
    @EnvironmentObject private var appState: AppState
    @State private var isLoading: Bool = false
    @State private var errorText: String? = nil
    @State private var playingId: String? = nil
    @State private var audioPlayer: AVAudioPlayer? = nil
    @State private var messageToDelete: VoicemailMessage? = nil

    public init() {}

    public var body: some View {
        ZStack {
            Color(.systemGroupedBackground)
                .ignoresSafeArea()

            if isLoading && appState.voicemailMessages.isEmpty {
                ProgressView()
            } else if appState.voicemailMessages.isEmpty {
                VStack(spacing: 12) {
                    Image(systemName: "recordingtape")
                        .font(.system(size: 48))
                        .foregroundColor(.secondary.opacity(0.6))
                    Text(L("No voicemail messages"))
                        .font(.subheadline)
                        .foregroundColor(.secondary)
                }
            } else {
                List {
                    ForEach(appState.voicemailMessages) { msg in
                        VStack(alignment: .leading, spacing: 8) {
                            HStack(spacing: 10) {
                                Button(action: { togglePlay(msg) }) {
                                    Image(systemName: playingId == msg.id ? "pause.circle.fill" : "play.circle.fill")
                                        .font(.system(size: 34))
                                        .foregroundColor(msg.hasAudio ? .blue : .gray.opacity(0.4))
                                }
                                .buttonStyle(BorderlessButtonStyle())
                                .disabled(!msg.hasAudio)

                                VStack(alignment: .leading, spacing: 3) {
                                    HStack(spacing: 6) {
                                        Text(callerDisplay(msg))
                                            .font(.system(size: 16, weight: .semibold))
                                            .foregroundColor(.primary)

                                        if msg.isNew {
                                            Text(L("New"))
                                                .font(.caption2.bold())
                                                .padding(.horizontal, 6)
                                                .padding(.vertical, 2)
                                                .background(Color.blue.opacity(0.15))
                                                .foregroundColor(.blue)
                                                .cornerRadius(4)
                                        }
                                    }

                                    Text(detailsDisplay(msg))
                                        .font(.caption)
                                        .foregroundColor(.secondary)
                                }

                                Spacer()

                                Button(action: { messageToDelete = msg }) {
                                    Image(systemName: "trash")
                                        .font(.system(size: 16))
                                        .foregroundColor(.red)
                                        .padding(8)
                                }
                                .buttonStyle(BorderlessButtonStyle())
                            }

                            let text = (msg.summary?.trimmingCharacters(in: .whitespacesAndNewlines)).flatMap { $0.isEmpty ? nil : $0 }
                                ?? (msg.transcript?.trimmingCharacters(in: .whitespacesAndNewlines)).flatMap { $0.isEmpty ? nil : $0 }
                            if let text = text {
                                Text(text)
                                    .font(.footnote)
                                    .foregroundColor(.secondary)
                                    .padding(.leading, 44)
                            }
                        }
                        .padding(.vertical, 4)
                    }
                }
                .listStyle(InsetGroupedListStyle())
                .refreshable {
                    await reload()
                }
            }
        }
        .navigationBarTitle(L("Voicemail"), displayMode: .inline)
        .navigationBarItems(trailing: Button(action: {
            Task { await reload() }
        }) {
            Image(systemName: "arrow.clockwise")
                .accessibilityLabel(L("Refresh"))
        })
        .onAppear {
            Task { await reload() }
        }
        .onDisappear {
            stopPlayback()
        }
        .alert(L("Voicemail"), isPresented: Binding(
            get: { messageToDelete != nil },
            set: { if !$0 { messageToDelete = nil } }
        ), presenting: messageToDelete) { msg in
            Button(L("Delete"), role: .destructive) { deleteMessage(msg) }
            Button(L("Cancel"), role: .cancel) {}
        } message: { _ in
            Text(L("Delete this voicemail message?"))
        }
        .alert(L("Error"), isPresented: Binding(
            get: { errorText != nil },
            set: { if !$0 { errorText = nil } }
        )) {
            Button(L("OK"), role: .cancel) {}
        } message: {
            Text(errorText ?? "")
        }
    }

    private func callerDisplay(_ msg: VoicemailMessage) -> String {
        let name = (msg.callerName ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        let num = (msg.callerNumber ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        if !name.isEmpty && !num.isEmpty && name != num {
            return "\(name) (\(num))"
        }
        if !name.isEmpty {
            return name
        }
        if !num.isEmpty && num.lowercased() != "unknown" {
            return num
        }
        return L("Unknown caller")
    }

    private func detailsDisplay(_ msg: VoicemailMessage) -> String {
        let mins = msg.duration / 60
        let secs = msg.duration % 60
        let dur = String(format: "%02d:%02d", mins, secs)
        if let t = msg.time, !t.isEmpty {
            return "\(t) · \(dur)"
        }
        return dur
    }

    private func reload() async {
        guard let token = appState.token else { return }
        await MainActor.run { isLoading = true }
        do {
            let res = try await ApiClient.shared.getVoicemail(baseUrl: appState.baseUrl, token: token)
            await MainActor.run {
                let msgs = res.messages ?? []
                appState.voicemailMessages = msgs
                appState.newVoicemailCount = res.newCount ?? msgs.filter { $0.isNew }.count
                isLoading = false
            }
        } catch {
            await MainActor.run {
                isLoading = false
                errorText = error.localizedDescription
            }
        }
    }

    private func togglePlay(_ msg: VoicemailMessage) {
        if playingId == msg.id {
            stopPlayback()
            return
        }
        stopPlayback()
        guard let token = appState.token else { return }
        playingId = msg.id

        Task {
            do {
                let data = try await ApiClient.shared.downloadVoicemail(baseUrl: appState.baseUrl, token: token, id: msg.id)
                await MainActor.run {
                    guard self.playingId == msg.id else { return }
                    do {
                        try AVAudioSession.sharedInstance().setCategory(.playback, mode: .spokenAudio)
                        try AVAudioSession.sharedInstance().setActive(true)
                        let player = try AVAudioPlayer(data: data)
                        player.prepareToPlay()
                        player.play()
                        self.audioPlayer = player
                        if msg.isNew {
                            self.markHeard(msg)
                        }
                    } catch {
                        self.stopPlayback()
                        self.errorText = L("The message could not be played")
                    }
                }
            } catch {
                await MainActor.run {
                    self.stopPlayback()
                    self.errorText = L("The message could not be played")
                }
            }
        }
    }

    private func markHeard(_ msg: VoicemailMessage) {
        guard let token = appState.token else { return }
        Task {
            if let res = try? await ApiClient.shared.voicemailAction(baseUrl: appState.baseUrl, token: token, action: "heard", id: msg.id) {
                await MainActor.run {
                    if let idx = appState.voicemailMessages.firstIndex(where: { $0.id == msg.id }) {
                        let newId = res.id ?? msg.id
                        appState.voicemailMessages[idx].id = newId
                        appState.voicemailMessages[idx].isNew = false
                        if playingId == msg.id {
                            playingId = newId
                        }
                        appState.newVoicemailCount = appState.voicemailMessages.filter { $0.isNew }.count
                    }
                }
            }
        }
    }

    private func deleteMessage(_ msg: VoicemailMessage) {
        if playingId == msg.id {
            stopPlayback()
        }
        guard let token = appState.token else { return }
        Task {
            do {
                _ = try await ApiClient.shared.voicemailAction(baseUrl: appState.baseUrl, token: token, action: "delete", id: msg.id)
                await MainActor.run {
                    appState.voicemailMessages.removeAll { $0.id == msg.id }
                    appState.newVoicemailCount = appState.voicemailMessages.filter { $0.isNew }.count
                }
            } catch {
                await MainActor.run {
                    errorText = error.localizedDescription
                }
            }
        }
    }

    private func stopPlayback() {
        audioPlayer?.stop()
        audioPlayer = nil
        playingId = nil
    }
}
