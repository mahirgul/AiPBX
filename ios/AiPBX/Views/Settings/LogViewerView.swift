import SwiftUI

public struct LogViewerView: View {
    @ObservedObject private var logManager = AppLogManager.shared
    @State private var filterLevel: String = "ALL"
    @State private var searchText: String = ""
    @State private var isSharePresented: Bool = false

    private let levels = ["ALL", "DEBUG", "INFO", "WARN", "ERROR"]

    public init() {}

    private var filteredLogs: [AppLogManager.LogEntry] {
        return logManager.logs.filter { entry in
            let matchesLevel = (filterLevel == "ALL") || (entry.level.rawValue == filterLevel)
            let matchesSearch = searchText.isEmpty ||
                entry.message.localizedCaseInsensitiveContains(searchText) ||
                entry.tag.localizedCaseInsensitiveContains(searchText)
            return matchesLevel && matchesSearch
        }
    }

    public var body: some View {
        VStack(spacing: 0) {
            // Filter Bar
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(levels, id: \.self) { lvl in
                        Button(action: { filterLevel = lvl }) {
                            Text(lvl)
                                .font(.caption.bold())
                                .padding(.horizontal, 12)
                                .padding(.vertical, 6)
                                .background(filterLevel == lvl ? Color.blue : Color(.systemGray6))
                                .foregroundColor(filterLevel == lvl ? .white : .primary)
                                .cornerRadius(12)
                        }
                    }
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 8)
            }

            // Search Field
            SearchBarView(text: $searchText, placeholder: "Günlüklerde ara...")
                .padding(.horizontal, 16)
                .padding(.bottom, 8)

            Divider()

            // Logs List
            if filteredLogs.isEmpty {
                Spacer()
                Text("Kayıt yok")
                    .foregroundColor(.secondary)
                Spacer()
            } else {
                ScrollViewReader { proxy in
                    ScrollView {
                        LazyVStack(alignment: .leading, spacing: 6) {
                            ForEach(filteredLogs) { item in
                                HStack(alignment: .top, spacing: 6) {
                                    Text(item.level.rawValue)
                                        .font(.system(size: 9, weight: .bold, design: .monospaced))
                                        .foregroundColor(levelColor(item.level))
                                        .padding(.horizontal, 4)
                                        .padding(.vertical, 2)
                                        .background(levelColor(item.level).opacity(0.12))
                                        .cornerRadius(4)

                                    VStack(alignment: .leading, spacing: 2) {
                                        Text("[\(item.tag)]")
                                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                            .foregroundColor(.secondary)

                                        Text(item.message)
                                            .font(.system(size: 11, design: .monospaced))
                                            .foregroundColor(.primary)
                                    }
                                }
                                .padding(.horizontal, 12)
                                .padding(.vertical, 2)
                                .id(item.id)
                            }
                        }
                        .padding(.vertical, 8)
                    }
                }
            }
        }
        .navigationBarTitle("Sistem Günlükleri", displayMode: .inline)
        .navigationBarItems(
            trailing: HStack(spacing: 16) {
                Button(action: { logManager.clear() }) {
                    Image(systemName: "trash")
                        .foregroundColor(.red)
                }

                Button(action: { isSharePresented = true }) {
                    Image(systemName: "square.and.arrow.up")
                }
            }
        )
        .sheet(isPresented: $isSharePresented) {
            ShareSheet(activityItems: [logManager.exportLogsAsText()])
        }
    }

    private func levelColor(_ level: AppLogManager.LogEntry.Level) -> Color {
        switch level {
        case .debug: return .gray
        case .info: return .blue
        case .warn: return .orange
        case .error: return .red
        }
    }
}

public struct ShareSheet: UIViewControllerRepresentable {
    public let activityItems: [Any]

    public func makeUIViewController(context: Context) -> UIActivityViewController {
        return UIActivityViewController(activityItems: activityItems, applicationActivities: nil)
    }

    public func updateUIViewController(_ uiViewController: UIActivityViewController, context: Context) {}
}
