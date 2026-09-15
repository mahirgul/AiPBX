import SwiftUI

public struct CallHistoryTabView: View {
    @EnvironmentObject private var appState: AppState
    @State private var selectedFilter: String = "all"
    @State private var isRefreshing: Bool = false

    private let filters: [(id: String, label: String)] = [
        ("all", "Tümü"),
        ("missed", "Cevapsız"),
        ("in", "Gelen"),
        ("out", "Giden")
    ]

    public init() {}

    private var filteredCalls: [CallRecord] {
        if selectedFilter == "all" {
            return appState.callHistory
        }
        return appState.callHistory.filter { $0.direction.lowercased() == selectedFilter }
    }

    public var body: some View {
        NavigationView {
            VStack(spacing: 0) {
                // Filter Chips Bar
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(filters, id: \.id) { item in
                            Button(action: {
                                selectedFilter = item.id
                            }) {
                                Text(item.label)
                                    .font(.subheadline.weight(selectedFilter == item.id ? .semibold : .regular))
                                    .padding(.horizontal, 14)
                                    .padding(.vertical, 8)
                                    .background(selectedFilter == item.id ? Color.blue : Color(.systemGray6))
                                    .foregroundColor(selectedFilter == item.id ? .white : .primary)
                                    .cornerRadius(18)
                            }
                        }
                    }
                    .padding(.horizontal, 16)
                    .padding(.vertical, 10)
                }
                .background(Color(.systemBackground))

                Divider()

                // Call List
                if filteredCalls.isEmpty {
                    Spacer()
                    VStack(spacing: 12) {
                        Image(systemName: "clock.arrow.circlepath")
                            .font(.system(size: 48))
                            .foregroundColor(.secondary.opacity(0.6))
                        Text("Kayıt bulunamadı")
                            .font(.subheadline)
                            .foregroundColor(.secondary)
                    }
                    Spacer()
                } else {
                    List {
                        ForEach(filteredCalls) { call in
                            CallRecordRowView(call: call) {
                                appState.makeCall(to: call.party)
                            }
                        }
                    }
                    .listStyle(PlainListStyle())
                    .refreshable {
                        await appState.refreshAllData()
                    }
                }
            }
            .navigationBarTitle("Geçmiş", displayMode: .inline)
        }
        .navigationViewStyle(StackNavigationViewStyle())
    }
}
