import SwiftUI

public struct SettingsTabView: View {
    @EnvironmentObject private var appState: AppState

    @State private var dndEnabled: Bool = false
    @State private var callForwardAlways: String = ""
    @State private var callForwardBusy: String = ""
    @State private var callForwardNoAnswer: String = ""
    @State private var noAnswerTimeout: Int = 20
    @State private var isSavingFeatures: Bool = false
    @State private var saveStatusText: String? = nil
    @State private var showLogoutAlert: Bool = false

    public init() {}

    public var body: some View {
        NavigationView {
            Form {
                // User Profile Section
                Section(header: Text("Kullanıcı Bilgileri")) {
                    HStack(spacing: 14) {
                        AvatarView(name: appState.userProfile?.displayName ?? "Kullanıcı", size: 54)

                        VStack(alignment: .leading, spacing: 4) {
                            Text(appState.userProfile?.displayName ?? "Bilinmiyor")
                                .font(.headline)

                            HStack(spacing: 6) {
                                Text("Dahili: \(appState.userProfile?.extensionNumber ?? "-")")
                                    .font(.subheadline)
                                    .foregroundColor(.secondary)

                                if let role = appState.userProfile?.role {
                                    RoleBadgeView(role: role)
                                }
                            }

                            Text(appState.baseUrl)
                                .font(.caption2)
                                .foregroundColor(.secondary)
                        }
                    }
                    .padding(.vertical, 4)
                }

                // PBX Features Section
                Section(header: Text("Santral Özellikleri")) {
                    Toggle("Rahatsız Etmeyin (DND)", isOn: $dndEnabled)

                    VStack(alignment: .leading, spacing: 6) {
                        Text("Her Zaman Yönlendir")
                            .font(.caption)
                            .foregroundColor(.secondary)
                        TextField("Dahili veya Numara", text: $callForwardAlways)
                            .keyboardType(.phonePad)
                    }

                    VStack(alignment: .leading, spacing: 6) {
                        Text("Meşgulde Yönlendir")
                            .font(.caption)
                            .foregroundColor(.secondary)
                        TextField("Dahili veya Numara", text: $callForwardBusy)
                            .keyboardType(.phonePad)
                    }

                    VStack(alignment: .leading, spacing: 6) {
                        Text("Cevapsızda Yönlendir")
                            .font(.caption)
                            .foregroundColor(.secondary)
                        TextField("Dahili veya Numara", text: $callForwardNoAnswer)
                            .keyboardType(.phonePad)
                    }

                    Picker("Çalma Süresi", selection: $noAnswerTimeout) {
                        Text("10 saniye").tag(10)
                        Text("15 saniye").tag(15)
                        Text("20 saniye").tag(20)
                        Text("30 saniye").tag(30)
                        Text("45 saniye").tag(45)
                    }

                    Button(action: saveFeatures) {
                        HStack {
                            Spacer()
                            if isSavingFeatures {
                                ProgressView()
                                    .padding(.trailing, 6)
                            }
                            Text("Ayarları Kaydet")
                                .fontWeight(.semibold)
                            Spacer()
                        }
                    }
                    .disabled(isSavingFeatures)

                    if let status = saveStatusText {
                        Text(status)
                            .font(.caption)
                            .foregroundColor(.green)
                    }
                }

                // Diagnostics & Logs Section
                Section(header: Text("Sistem & Teşhis")) {
                    NavigationLink(destination: LogViewerView()) {
                        Label("Sistem Günlükleri", systemImage: "text.alignleft")
                    }

                    HStack {
                        Text("SIP Durumu")
                        Spacer()
                        Text(appState.connectionStatus.localizedText)
                            .foregroundColor(appState.connectionStatus == .connected ? .green : .secondary)
                    }

                    HStack {
                        Text("Sohbet Servisi")
                        Spacer()
                        Text(ChatWebSocketManager.shared.isConnected ? "Bağlı" : "Bağlantı Yok")
                            .foregroundColor(ChatWebSocketManager.shared.isConnected ? .green : .secondary)
                    }
                }

                // Logout Section
                Section {
                    Button(role: .destructive, action: { showLogoutAlert = true }) {
                        HStack {
                            Spacer()
                            Label("Çıkış Yap", systemImage: "rectangle.portrait.and.arrow.right")
                                .fontWeight(.semibold)
                            Spacer()
                        }
                    }
                }
            }
            .navigationBarTitle("Santral", displayMode: .inline)
            .onAppear {
                loadCurrentFeatures()
            }
            .alert(isPresented: $showLogoutAlert) {
                Alert(
                    title: Text("Çıkış Yap"),
                    message: Text("Oturumunuz kapatılacaktır. Onaylıyor musunuz?"),
                    primaryButton: .destructive(Text("Çıkış")) {
                        appState.logout()
                    },
                    secondaryButton: .cancel(Text("Vazgeç"))
                )
            }
        }
        .navigationViewStyle(StackNavigationViewStyle())
    }

    private func loadCurrentFeatures() {
        if let f = appState.features {
            self.dndEnabled = f.dndEnabled
            self.callForwardAlways = f.callForwardNumber ?? ""
            self.callForwardBusy = f.cfBusyNumber ?? ""
            self.callForwardNoAnswer = f.cfNoAnswerNumber ?? ""
            self.noAnswerTimeout = f.cfNoAnswerTimeout ?? 20
        }
    }

    private func saveFeatures() {
        guard var current = appState.features else { return }
        isSavingFeatures = true
        saveStatusText = nil

        current.dndEnabled = dndEnabled
        current.callForwardNumber = callForwardAlways.isEmpty ? nil : callForwardAlways
        current.cfBusyNumber = callForwardBusy.isEmpty ? nil : callForwardBusy
        current.cfNoAnswerNumber = callForwardNoAnswer.isEmpty ? nil : callForwardNoAnswer
        current.cfNoAnswerTimeout = noAnswerTimeout

        Task {
            await appState.updateFeatures(current)
            await MainActor.run {
                self.isSavingFeatures = false
                self.saveStatusText = "✓ Ayarlar başarıyla kaydedildi"
                DispatchQueue.main.asyncAfter(deadline: .now() + 3.0) {
                    self.saveStatusText = nil
                }
            }
        }
    }
}
