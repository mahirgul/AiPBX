import SwiftUI

public struct DialerTabView: View {
    @EnvironmentObject private var appState: AppState
    @State private var dialedNumber: String = ""

    public init() {}

    public var body: some View {
        NavigationView {
            ZStack {
                Color(.systemGroupedBackground)
                    .ignoresSafeArea()

                VStack(spacing: 16) {
                    // Header Bar (Status & Extension)
                    HStack {
                        HStack(spacing: 6) {
                            Circle()
                                .fill(statusColor)
                                .frame(width: 10, height: 10)
                            Text(appState.connectionStatus.localizedText)
                                .font(.footnote.weight(.medium))
                                .foregroundColor(.secondary)
                        }

                        Spacer()

                        if let ext = appState.userProfile?.extensionNumber {
                            HStack(spacing: 4) {
                                Image(systemName: "phone.fill")
                                    .font(.caption2)
                                Text("Dahili: \(ext)")
                                    .font(.footnote.bold())
                            }
                            .padding(.horizontal, 10)
                            .padding(.vertical, 4)
                            .background(Color.blue.opacity(0.12))
                            .foregroundColor(.blue)
                            .cornerRadius(12)
                        }
                    }
                    .padding(.horizontal, 20)
                    .padding(.top, 10)

                    Spacer(minLength: 10)

                    // Dialed Number Display
                    HStack {
                        Spacer()

                        Text(dialedNumber.isEmpty ? "Numara Çevirin" : dialedNumber)
                            .font(.system(size: dialedNumber.isEmpty ? 24 : 36, weight: .semibold, design: .rounded))
                            .foregroundColor(dialedNumber.isEmpty ? .secondary.opacity(0.6) : .primary)
                            .lineLimit(1)
                            .minimumScaleFactor(0.6)

                        Spacer()

                        if !dialedNumber.isEmpty {
                            Button(action: {
                                if !dialedNumber.isEmpty {
                                    dialedNumber.removeLast()
                                }
                            }) {
                                Image(systemName: "delete.left.fill")
                                    .font(.title2)
                                    .foregroundColor(.secondary)
                            }
                            .padding(.trailing, 20)
                        }
                    }
                    .frame(height: 50)
                    .padding(.horizontal)

                    Spacer(minLength: 10)

                    // Keypad Matrix
                    DialKeypadView { digit in
                        dialedNumber.append(digit)
                    }

                    Spacer(minLength: 16)

                    // Call Button
                    Button(action: {
                        if !dialedNumber.isEmpty {
                            appState.makeCall(to: dialedNumber)
                        }
                    }) {
                        ZStack {
                            Circle()
                                .fill(dialedNumber.isEmpty ? Color.gray.opacity(0.4) : Color.green)
                                .frame(width: 72, height: 72)
                                .shadow(color: dialedNumber.isEmpty ? .clear : .green.opacity(0.4), radius: 8, y: 4)

                            Image(systemName: "phone.fill")
                                .font(.system(size: 30))
                                .foregroundColor(.white)
                        }
                    }
                    .disabled(dialedNumber.isEmpty)
                    .padding(.bottom, 24)
                }
            }
            .navigationBarTitle("Tuşlar", displayMode: .inline)
        }
        .navigationViewStyle(StackNavigationViewStyle())
    }

    private var statusColor: Color {
        switch appState.connectionStatus {
        case .connected: return .green
        case .connecting: return .orange
        case .disconnected: return .red
        }
    }
}
