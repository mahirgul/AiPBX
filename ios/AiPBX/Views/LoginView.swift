import SwiftUI

public struct LoginView: View {
    @EnvironmentObject private var appState: AppState

    @State private var serverUrl: String = ""
    @State private var username: String = ""
    @State private var password: String = ""
    @State private var isSecured: Bool = true
    @State private var isPinging: Bool = false
    @State private var pingStatusText: String? = nil
    @State private var isShowingScanner: Bool = false

    public init() {}

    public var body: some View {
        NavigationView {
            ZStack {
                Color(.systemGroupedBackground)
                    .ignoresSafeArea()

                ScrollView {
                    VStack(spacing: 24) {
                        // Header / Logo
                        VStack(spacing: 12) {
                            ZStack {
                                Circle()
                                    .fill(LinearGradient(colors: [.blue, .indigo], startPoint: .topLeading, endPoint: .bottomTrailing))
                                    .frame(width: 88, height: 88)
                                    .shadow(color: .blue.opacity(0.3), radius: 10, y: 5)

                                Image(systemName: "phone.bubble.left.fill")
                                    .font(.system(size: 42))
                                    .foregroundColor(.white)
                            }
                            .padding(.top, 40)

                            Text("AiPBX")
                                .font(.system(size: 32, weight: .bold, design: .rounded))

                            Text("Kurumsal İletişim Platformu")
                                .font(.subheadline)
                                .foregroundColor(.secondary)
                        }

                        // Error Banner
                        if let error = appState.errorMessage {
                            HStack(spacing: 8) {
                                Image(systemName: "exclamationmark.triangle.fill")
                                    .foregroundColor(.red)
                                Text(error)
                                    .font(.footnote)
                                    .foregroundColor(.red)
                                Spacer()
                            }
                            .padding(12)
                            .background(Color.red.opacity(0.1))
                            .cornerRadius(10)
                            .padding(.horizontal)
                        }

                        // Form Card
                        VStack(spacing: 18) {
                            // Server URL Field
                            VStack(alignment: .leading, spacing: 6) {
                                Label("Santral Sunucu Adresi", systemImage: "server.rack")
                                    .font(.caption)
                                    .foregroundColor(.secondary)

                                HStack {
                                    TextField("http://10.8.0.10", text: $serverUrl)
                                        .autocapitalization(.none)
                                        .disableAutocorrection(true)
                                        .keyboardType(.URL)

                                    if isPinging {
                                        ProgressView()
                                            .scaleEffect(0.8)
                                    } else {
                                        Button(action: testPing) {
                                            Text("Test")
                                                .font(.caption2.bold())
                                                .padding(.horizontal, 8)
                                                .padding(.vertical, 4)
                                                .background(Color.blue.opacity(0.15))
                                                .foregroundColor(.blue)
                                                .cornerRadius(6)
                                        }
                                    }
                                }
                                .padding(12)
                                .background(Color(.systemBackground))
                                .cornerRadius(10)

                                if let pingStatus = pingStatusText {
                                    Text(pingStatus)
                                        .font(.caption2)
                                        .foregroundColor(pingStatus.contains("Başarılı") ? .green : .orange)
                                }
                            }

                            // Username / Extension Field
                            VStack(alignment: .leading, spacing: 6) {
                                Label("Dahili veya Kullanıcı Adı", systemImage: "person.fill")
                                    .font(.caption)
                                    .foregroundColor(.secondary)

                                TextField("Örn: 101", text: $username)
                                    .autocapitalization(.none)
                                    .disableAutocorrection(true)
                                    .padding(12)
                                    .background(Color(.systemBackground))
                                    .cornerRadius(10)
                            }

                            // Password Field
                            VStack(alignment: .leading, spacing: 6) {
                                Label("Şifre", systemImage: "lock.fill")
                                    .font(.caption)
                                    .foregroundColor(.secondary)

                                HStack {
                                    if isSecured {
                                        SecureField("Şifrenizi girin", text: $password)
                                    } else {
                                        TextField("Şifrenizi girin", text: $password)
                                            .autocapitalization(.none)
                                            .disableAutocorrection(true)
                                    }

                                    Button(action: { isSecured.toggle() }) {
                                        Image(systemName: isSecured ? "eye.slash" : "eye")
                                            .foregroundColor(.secondary)
                                    }
                                }
                                .padding(12)
                                .background(Color(.systemBackground))
                                .cornerRadius(10)
                            }

                            // Remember Me Toggle
                            Toggle("Beni Hatırla", isOn: $appState.rememberMe)
                                .font(.subheadline)
                                .padding(.top, 4)

                            // Login Button
                            Button(action: performLogin) {
                                HStack {
                                    if appState.isLoading {
                                        ProgressView()
                                            .progressViewStyle(CircularProgressViewStyle(tint: .white))
                                            .padding(.trailing, 4)
                                    }
                                    Text(appState.isLoading ? "Giriş Yapılıyor..." : "Giriş Yap")
                                        .font(.headline)
                                }
                                .frame(maxWidth: .infinity)
                                .frame(height: 50)
                                .background(LinearGradient(colors: [.blue, .indigo], startPoint: .leading, endPoint: .trailing))
                                .foregroundColor(.white)
                                .cornerRadius(12)
                                .shadow(color: .blue.opacity(0.3), radius: 6, y: 3)
                            }
                            .disabled(appState.isLoading || username.isEmpty || password.isEmpty)
                            .opacity((username.isEmpty || password.isEmpty) ? 0.6 : 1.0)
                            .padding(.top, 8)

                            // Divider
                            HStack {
                                Rectangle()
                                    .fill(Color.secondary.opacity(0.3))
                                    .frame(height: 1)
                                Text("veya")
                                    .font(.caption)
                                    .foregroundColor(.secondary)
                                Rectangle()
                                    .fill(Color.secondary.opacity(0.3))
                                    .frame(height: 1)
                            }
                            .padding(.vertical, 4)

                            // Google Login Button
                            Button(action: performGoogleLogin) {
                                HStack(spacing: 10) {
                                    Image(systemName: "g.circle.fill")
                                        .font(.title3)
                                        .foregroundColor(.red)
                                    Text("Google ile Giriş Yap")
                                        .font(.subheadline.bold())
                                        .foregroundColor(.primary)
                                }
                                .frame(maxWidth: .infinity)
                                .frame(height: 48)
                                .background(Color(.systemBackground))
                                .cornerRadius(12)
                                .overlay(
                                    RoundedRectangle(cornerRadius: 12)
                                        .stroke(Color.secondary.opacity(0.3), lineWidth: 1)
                                )
                            }
                            .disabled(appState.isLoading || serverUrl.isEmpty)

                            // QR Kod ile Hızlı Giriş Butonu
                            Button(action: { isShowingScanner = true }) {
                                HStack(spacing: 10) {
                                    Image(systemName: "qrcode.viewfinder")
                                        .font(.title3)
                                        .foregroundColor(.blue)
                                    Text("QR Kod ile Giriş Yap")
                                        .font(.subheadline.bold())
                                        .foregroundColor(.blue)
                                }
                                .frame(maxWidth: .infinity)
                                .frame(height: 48)
                                .background(Color.blue.opacity(0.08))
                                .cornerRadius(12)
                                .overlay(
                                    RoundedRectangle(cornerRadius: 12)
                                        .stroke(Color.blue.opacity(0.4), lineWidth: 1)
                                )
                            }
                            .disabled(appState.isLoading)
                        }
                        .padding(20)
                        .background(Color(.secondarySystemGroupedBackground))
                        .cornerRadius(16)
                        .padding(.horizontal)

                        // Version Footnote
                        Text("Sürüm: 1.0.34 · Asterisk 22 WebRTC")
                            .font(.caption2)
                            .foregroundColor(.secondary)
                            .padding(.bottom, 30)
                    }
                }
            }
            .navigationBarHidden(true)
            .sheet(isPresented: $isShowingScanner) {
                NavigationView {
                    QRCodeScannerView { scannedCode in
                        handleScannedQrCode(scannedCode)
                    }
                    .navigationTitle("QR Kod Tara")
                    .navigationBarTitleDisplayMode(.inline)
                    .toolbar {
                        ToolbarItem(placement: .cancellationAction) {
                            Button("İptal") {
                                isShowingScanner = false
                            }
                        }
                    }
                }
            }
            .onAppear {
                self.serverUrl = appState.baseUrl
                self.username = appState.savedUsername
            }
        }
        .navigationViewStyle(StackNavigationViewStyle())
    }

    private func handleScannedQrCode(_ code: String) {
        guard let data = code.data(using: .utf8) else {
            appState.errorMessage = "QR kod okunamadı."
            return
        }

        do {
            if let json = try JSONSerialization.jsonObject(with: data) as? [String: Any],
               let type = json["type"] as? String, type == "aipbx_qr_login",
               let server = json["server"] as? String,
               let qrToken = json["qr_token"] as? String {
                self.serverUrl = server
                Task {
                    let success = await appState.loginWithQr(serverUrl: server, qrToken: qrToken)
                    if !success && appState.errorMessage == nil {
                        appState.errorMessage = "QR kod ile giriş başarısız oldu."
                    }
                }
            } else {
                appState.errorMessage = "Geçersiz veya uyumsuz AiPBX QR kodu."
            }
        } catch {
            appState.errorMessage = "QR kod çözümlenemedi: \(error.localizedDescription)"
        }
    }

    private func performLogin() {
        guard !username.isEmpty, !password.isEmpty else { return }
        Task {
            _ = await appState.login(serverUrl: serverUrl, username: username, pass: password)
        }
    }

    private func performGoogleLogin() {
        let cleanBase = serverUrl.trimmingCharacters(in: .whitespacesAndNewlines).trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard !cleanBase.isEmpty, let authUrl = URL(string: "\(cleanBase)/auth/google?mobile=1&platform=ios") else {
            appState.errorMessage = "Lütfen geçerli bir santral sunucu adresi girin."
            return
        }
        UIApplication.shared.open(authUrl)
    }

    private func testPing() {
        guard !serverUrl.isEmpty else { return }
        isPinging = true
        pingStatusText = nil
        Task {
            do {
                let info = try await ApiClient.shared.ping(baseUrl: serverUrl)
                await MainActor.run {
                    self.isPinging = false
                    self.pingStatusText = "✓ Bağlantı Başarılı: \(info.service ?? "PBX") \(info.version ?? "")"
                }
            } catch {
                await MainActor.run {
                    self.isPinging = false
                    self.pingStatusText = "✗ Sunucuya ulaşılamadı"
                }
            }
        }
    }
}

// MARK: - AVFoundation QR Scanner View
import AVFoundation
import AudioToolbox

struct QRCodeScannerView: UIViewControllerRepresentable {
    var onScan: (String) -> Void
    @Environment(\.presentationMode) var presentationMode

    func makeUIViewController(context: Context) -> ScannerViewController {
        let controller = ScannerViewController()
        controller.delegate = context.coordinator
        return controller
    }

    func updateUIViewController(_ uiViewController: ScannerViewController, context: Context) {}

    func makeCoordinator() -> Coordinator {
        Coordinator(parent: self)
    }

    class Coordinator: NSObject, ScannerViewControllerDelegate {
        let parent: QRCodeScannerView

        init(parent: QRCodeScannerView) {
            self.parent = parent
        }

        func didFindCode(_ code: String) {
            parent.onScan(code)
            parent.presentationMode.wrappedValue.dismiss()
        }
    }
}

protocol ScannerViewControllerDelegate: AnyObject {
    func didFindCode(_ code: String)
}

final class ScannerViewController: UIViewController, AVCaptureMetadataOutputObjectsDelegate {
    weak var delegate: ScannerViewControllerDelegate?
    private var captureSession: AVCaptureSession?
    private var previewLayer: AVCaptureVideoPreviewLayer?

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .black

        let session = AVCaptureSession()
        guard let videoCaptureDevice = AVCaptureDevice.default(for: .video) else { return }
        guard let videoInput = try? AVCaptureDeviceInput(device: videoCaptureDevice) else { return }

        if session.canAddInput(videoInput) {
            session.addInput(videoInput)
        } else {
            return
        }

        let metadataOutput = AVCaptureMetadataOutput()
        if session.canAddOutput(metadataOutput) {
            session.addOutput(metadataOutput)
            metadataOutput.setMetadataObjectsDelegate(self, queue: DispatchQueue.main)
            metadataOutput.metadataObjectTypes = [.qr]
        } else {
            return
        }

        let preview = AVCaptureVideoPreviewLayer(session: session)
        preview.frame = view.layer.bounds
        preview.videoGravity = .resizeAspectFill
        view.layer.addSublayer(preview)
        self.previewLayer = preview
        self.captureSession = session

        DispatchQueue.global(qos: .userInitiated).async {
            session.startRunning()
        }
    }

    override func viewDidLayoutSubviews() {
        super.viewDidLayoutSubviews()
        previewLayer?.frame = view.layer.bounds
    }

    override func viewWillDisappear(_ animated: Bool) {
        super.viewWillDisappear(animated)
        if captureSession?.isRunning == true {
            captureSession?.stopRunning()
        }
    }

    func metadataOutput(_ output: AVCaptureMetadataOutput, didOutput metadataObjects: [AVMetadataObject], from connection: AVCaptureConnection) {
        if let metadataObject = metadataObjects.first as? AVMetadataMachineReadableCodeObject,
           let stringValue = metadataObject.stringValue {
            AudioServicesPlaySystemSound(SystemSoundID(kSystemSoundID_Vibrate))
            captureSession?.stopRunning()
            delegate?.didFindCode(stringValue)
        }
    }
}

