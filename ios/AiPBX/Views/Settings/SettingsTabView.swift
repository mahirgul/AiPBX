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
                Section(header: Text(L("User Info"))) {
                    HStack(spacing: 14) {
                        AvatarView(name: appState.userProfile?.displayName ?? L("User"), size: 54)

                        VStack(alignment: .leading, spacing: 4) {
                            Text(appState.userProfile?.displayName ?? L("Unknown"))
                                .font(.headline)

                            HStack(spacing: 6) {
                                Text(L("Extension: %@", appState.userProfile?.extensionNumber ?? "-"))
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
                Section(header: Text(L("PBX Features"))) {
                    Toggle(L("Do Not Disturb (DND)"), isOn: $dndEnabled)

                    VStack(alignment: .leading, spacing: 6) {
                        Text(L("Always Forward"))
                            .font(.caption)
                            .foregroundColor(.secondary)
                        TextField(L("Extension or Number"), text: $callForwardAlways)
                            .keyboardType(.phonePad)
                    }

                    VStack(alignment: .leading, spacing: 6) {
                        Text(L("Forward When Busy"))
                            .font(.caption)
                            .foregroundColor(.secondary)
                        TextField(L("Extension or Number"), text: $callForwardBusy)
                            .keyboardType(.phonePad)
                    }

                    VStack(alignment: .leading, spacing: 6) {
                        Text(L("Forward When Unanswered"))
                            .font(.caption)
                            .foregroundColor(.secondary)
                        TextField(L("Extension or Number"), text: $callForwardNoAnswer)
                            .keyboardType(.phonePad)
                    }

                    Picker(L("Ring Time"), selection: $noAnswerTimeout) {
                        Text(L("10 seconds")).tag(10)
                        Text(L("15 seconds")).tag(15)
                        Text(L("20 seconds")).tag(20)
                        Text(L("30 seconds")).tag(30)
                        Text(L("45 seconds")).tag(45)
                    }

                    Button(action: saveFeatures) {
                        HStack {
                            Spacer()
                            if isSavingFeatures {
                                ProgressView()
                                    .padding(.trailing, 6)
                            }
                            Text(L("Save Settings"))
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

                // Language Section
                Section(header: Text(L("Language"))) {
                    Picker(L("Language"), selection: Binding(
                        get: { AppLanguage.shared.code },
                        set: { AppLanguage.shared.set($0) }
                    )) {
                        ForEach(AppLanguage.supported, id: \.self) { code in
                            Text(AppLanguage.names[code] ?? code).tag(code)
                        }
                    }
                }

                // Diagnostics & Logs Section
                Section(header: Text(L("System & Diagnostics"))) {
                    NavigationLink(destination: LogViewerView()) {
                        Label(L("System Logs"), systemImage: "text.alignleft")
                    }

                    HStack {
                        Text(L("SIP Status"))
                        Spacer()
                        Text(appState.connectionStatus.localizedText)
                            .foregroundColor(appState.connectionStatus == .connected ? .green : .secondary)
                    }

                    HStack {
                        Text(L("Chat Service"))
                        Spacer()
                        Text(ChatWebSocketManager.shared.isConnected ? L("Connected") : L("Not Connected"))
                            .foregroundColor(ChatWebSocketManager.shared.isConnected ? .green : .secondary)
                    }
                }

                // Logout Section
                Section {
                    Button(role: .destructive, action: { showLogoutAlert = true }) {
                        HStack {
                            Spacer()
                            Label(L("Sign Out"), systemImage: "rectangle.portrait.and.arrow.right")
                                .fontWeight(.semibold)
                            Spacer()
                        }
                    }
                }
            }
            .navigationBarTitle(L("PBX"), displayMode: .inline)
            .onAppear {
                loadCurrentFeatures()
            }
            .alert(isPresented: $showLogoutAlert) {
                Alert(
                    title: Text(L("Sign Out")),
                    message: Text(L("You will be signed out. Continue?")),
                    primaryButton: .destructive(Text(L("Sign out"))) {
                        appState.logout()
                    },
                    secondaryButton: .cancel(Text(L("Cancel")))
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
                self.saveStatusText = L("✓ Settings saved")
                DispatchQueue.main.asyncAfter(deadline: .now() + 3.0) {
                    self.saveStatusText = nil
                }
            }
        }
    }
}
