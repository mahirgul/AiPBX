import SwiftUI

@main
struct AiPBXApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate
    @StateObject private var appState = AppState.shared
    @StateObject private var language = AppLanguage.shared
    @AppStorage("aipbx_disclaimer_accepted_v1") private var disclaimerAccepted: Bool = false

    var body: some Scene {
        WindowGroup {
            Group {
                if appState.isLoggedIn {
                    MainTabView()
                } else {
                    LoginView()
                }
            }
            // A new language rebuilds every screen with the new texts.
            .id(language.code)
            .environment(\.locale, Locale(identifier: language.code))
            .environmentObject(appState)
            .preferredColorScheme(.none) // Supports both Dark and Light mode automatically
            .onChange(of: language.code) { _ in
                // The Reply action's titles follow the app language.
                ChatNotifications.shared.registerCategory()
            }
            .onOpenURL { url in
                appState.handleDeepLinkUrl(url)
            }
            .alert(item: $appState.pendingLinkLogin) { pending in
                Alert(
                    title: Text(L("Mobile Sign-in")),
                    message: Text(L("Sign in to the \"%@\" PBX?\n\nOnly confirm if you opened this link from an e-mail sent by your organization or from the QR code on your own screen.", pending.host)
                                  + (appState.isLoggedIn ? "\n\n" + L("The open session is closed if this sign-in succeeds.") : "")),
                    primaryButton: .default(Text(L("Sign In"))) { appState.confirmPendingLinkLogin() },
                    secondaryButton: .cancel(Text(L("Cancel")))
                )
            }
            .fullScreenCover(isPresented: Binding(
                get: { !disclaimerAccepted },
                set: { if !$0 { disclaimerAccepted = true } }
            )) {
                FirstLaunchDisclaimerView(
                    isTurkish: language.code.lowercased().hasPrefix("tr"),
                    onAccept: { disclaimerAccepted = true }
                )
            }
        }
    }
}

private struct FirstLaunchDisclaimerView: View {
    let isTurkish: Bool
    let onAccept: () -> Void
    @State private var agreed: Bool = false

    var body: some View {
        NavigationView {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    HStack(spacing: 12) {
                        Image(systemName: "checkmark.shield.fill")
                            .font(.system(size: 32))
                            .foregroundColor(.blue)
                        Text(isTurkish
                             ? "Açık Kaynak Lisansı ve Sorumluluk Beyanı"
                             : "Open Source License & Telecom Disclaimer")
                            .font(.title3.bold())
                    }
                    .padding(.top, 8)

                    Text(isTurkish
                         ? "AiPBX, MIT Lisansı altında hiçbir garanti olmaksızın \"OLDUĞU GİBİ\" (AS IS) sunulan açık kaynaklı bir kurumsal iletişim uygulamasıdır. Uygulamayı kullanarak aşağıdaki şartları kabul etmiş sayılırsınız:"
                         : "AiPBX is open-source software provided \"AS IS\" under the MIT License without warranty of any kind. By using this application, you acknowledge and agree to the following:")
                        .font(.subheadline)
                        .foregroundColor(.secondary)

                    VStack(alignment: .leading, spacing: 14) {
                        disclaimerBullet(
                            icon: "phone.badge.exclamationmark",
                            title: isTurkish ? "Acil Çağrılar (112 / 911 / 999)" : "Emergency Calls (112 / 911 / 999)",
                            body: isTurkish
                                ? "Bu VoIP uygulaması internet ve santral erişimine bağlıdır; kesinti durumlarında acil çağrı yapamayabilir. Acil durumlar için her zaman telefonunuzun kendi hücresel (GSM) arama ekranını kullanın."
                                : "This VoIP softphone depends on internet and PBX connectivity and is not a replacement for cellular emergency calling. Always use your device's native cellular dialer for emergency calls."
                        )
                        disclaimerBullet(
                            icon: "lock.shield",
                            title: isTurkish ? "Veri Gizliliği (KVKK / GDPR)" : "Data Privacy (GDPR / KVKK)",
                            body: isTurkish
                                ? "Sesli görüşmeler, çağrı kayıtları ve mesajlar bağlandığınız santral sunucusu tarafından kurumunuzun veri gizliliği politikasına göre işlenir."
                                : "Voice calls, recordings, and messages are processed by the PBX server you connect to in accordance with your organization's privacy policies."
                        )
                        disclaimerBullet(
                            icon: "doc.text",
                            title: isTurkish ? "Garanti ve Sorumluluk Reddi (MIT)" : "No Warranty & Limitation of Liability (MIT)",
                            body: isTurkish
                                ? "Yazılım geliştiricileri ve katkıda bulunanlar, uygulamanın kullanımından doğabilecek doğrudan veya dolaylı zararlardan sorumlu tutulamaz."
                                : "The authors and contributors shall not be liable for any direct, indirect, or consequential damages arising from the use of this software."
                        )
                    }
                    .padding(16)
                    .background(Color(.secondarySystemGroupedBackground))
                    .cornerRadius(12)

                    Toggle(isOn: $agreed) {
                        Text(isTurkish
                             ? "Yukarıdaki lisans, acil çağrı ve sorumluluk beyanını okudum ve kabul ediyorum."
                             : "I have read, understood, and accept the license, emergency calling, and responsibility disclaimer.")
                            .font(.footnote.weight(.semibold))
                    }
                    .padding(.vertical, 6)

                    Button(action: onAccept) {
                        HStack {
                            Spacer()
                            Text(isTurkish ? "Kabul Et ve Devam Et" : "Accept & Continue")
                                .font(.headline)
                            Spacer()
                        }
                        .padding(.vertical, 14)
                        .background(agreed ? Color.blue : Color.gray.opacity(0.4))
                        .foregroundColor(.white)
                        .cornerRadius(12)
                    }
                    .disabled(!agreed)
                }
                .padding(22)
            }
            .background(Color(.systemGroupedBackground).ignoresSafeArea())
            .navigationBarHidden(true)
        }
        .interactiveDismissDisabled(true)
    }

    private func disclaimerBullet(icon: String, title: String, body: String) -> some View {
        HStack(alignment: .top, spacing: 12) {
            Image(systemName: icon)
                .foregroundColor(.blue)
                .font(.system(size: 18))
                .frame(width: 24)
            VStack(alignment: .leading, spacing: 4) {
                Text(title).font(.subheadline.bold())
                Text(body).font(.footnote).foregroundColor(.secondary)
            }
        }
    }
}
