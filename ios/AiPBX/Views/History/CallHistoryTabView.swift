import SwiftUI

public struct CallHistoryTabView: View {
    @EnvironmentObject private var appState: AppState
    @State private var selectedFilter: String = "all"
    @State private var isRefreshing: Bool = false
    /// A swiped-away call while its Undo is offered; sent to the server after.
    @State private var pendingRemoval: CallRecord? = nil
    @State private var showClearConfirm: Bool = false
    @State private var actionError: String? = nil

    /// How long Undo is offered after a call is swiped away.
    private let undoSeconds: UInt64 = 4

    private let filters: [(id: String, label: String)] = [
        ("all", L("All")),
        ("missed", L("Missed")),
        ("in", L("Incoming")),
        ("out", L("Outgoing"))
    ]

    public init() {}

    private var filteredCalls: [CallRecord] {
        if selectedFilter == "all" {
            return appState.callHistory
        }
        return appState.callHistory.filter { $0.direction.lowercased() == selectedFilter }
    }

    /// Older servers send no call_key: then calls cannot be removed.
    private var canRemoveCalls: Bool {
        appState.callHistory.contains { !($0.callKey ?? "").isEmpty }
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
                        Text(L("No records found"))
                            .font(.subheadline)
                            .foregroundColor(.secondary)
                    }
                    Spacer()
                } else {
                    List {
                        ForEach(filteredCalls) { call in
                            if !(call.callKey ?? "").isEmpty {
                                CallRecordRowView(call: call) {
                                    appState.makeCall(to: call.party)
                                }
                                .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                                    Button(role: .destructive) {
                                        removeCall(call)
                                    } label: {
                                        Label(L("Delete"), systemImage: "trash")
                                    }
                                }
                            } else {
                                CallRecordRowView(call: call) {
                                    appState.makeCall(to: call.party)
                                }
                            }
                        }
                    }
                    .listStyle(PlainListStyle())
                    .refreshable {
                        await appState.refreshAllData()
                        dropPendingRemoval()
                    }
                }
            }
            .overlay(alignment: .bottom) {
                if pendingRemoval != nil {
                    undoBanner
                        .transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            .navigationBarTitle(L("History"), displayMode: .inline)
            .navigationBarItems(trailing: clearButton)
            .alert(L("Clear call history"), isPresented: $showClearConfirm) {
                Button(L("Clear"), role: .destructive) { clearHistory() }
                Button(L("Cancel"), role: .cancel) {}
            } message: {
                Text(L("Remove all calls from your call history? The call records on the server are kept."))
            }
            .alert(L("Error"), isPresented: Binding(
                get: { actionError != nil },
                set: { if !$0 { actionError = nil } }
            )) {
                Button(L("OK"), role: .cancel) {}
            } message: {
                Text(actionError ?? "")
            }
        }
        .navigationViewStyle(StackNavigationViewStyle())
    }

    @ViewBuilder
    private var clearButton: some View {
        if canRemoveCalls {
            Button(action: { showClearConfirm = true }) {
                Image(systemName: "trash")
            }
            .accessibilityLabel(L("Clear call history"))
        }
    }

    private var undoBanner: some View {
        HStack {
            Text(L("Call removed from your history"))
                .font(.subheadline)
                .foregroundColor(.white)
            Spacer()
            Button(L("Undo")) { undoRemoval() }
                .font(.subheadline.bold())
                .foregroundColor(.yellow)
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .background(Color.black.opacity(0.85))
        .cornerRadius(10)
        .padding(.horizontal, 12)
        .padding(.bottom, 8)
    }

    /// Takes the call off the list at once and offers Undo; the server is
    /// told only when Undo was not used. The call records on the server stay.
    private func removeCall(_ call: CallRecord) {
        // A previous removal still offering Undo is final now.
        if let previous = pendingRemoval {
            commitRemoval(previous)
        }
        withAnimation {
            appState.callHistory.removeAll { $0.id == call.id }
            pendingRemoval = call
        }
        Task { @MainActor in
            try? await Task.sleep(nanoseconds: undoSeconds * 1_000_000_000)
            if pendingRemoval?.id == call.id {
                commitRemoval(call)
            }
        }
    }

    private func undoRemoval() {
        guard let call = pendingRemoval else { return }
        withAnimation {
            pendingRemoval = nil
            if !appState.callHistory.contains(where: { $0.id == call.id }) {
                appState.callHistory.append(call)
                appState.callHistory.sort { $0.calldate > $1.calldate }
            }
        }
    }

    private func commitRemoval(_ call: CallRecord) {
        if pendingRemoval?.id == call.id {
            withAnimation { pendingRemoval = nil }
        }
        guard let key = call.callKey, !key.isEmpty else { return }
        hideOnServer(callKeys: [key])
    }

    private func clearHistory() {
        pendingRemoval = nil
        hideOnServer(callKeys: nil)
    }

    /// callKeys nil clears the whole history.
    private func hideOnServer(callKeys: [String]?) {
        guard let token = appState.token else { return }
        let baseUrl = appState.baseUrl
        Task { @MainActor in
            do {
                try await ApiClient.shared.hideCalls(baseUrl: baseUrl, token: token, callKeys: callKeys)
                if callKeys == nil {
                    appState.callHistory.removeAll()
                }
            } catch {
                actionError = L("Could not remove: %@", error.localizedDescription)
            }
            await appState.refreshCallHistory()
            dropPendingRemoval()
        }
    }

    /// A reload brings back a call whose Undo is still offered: keep it hidden.
    private func dropPendingRemoval() {
        if let pending = pendingRemoval {
            appState.callHistory.removeAll { $0.id == pending.id }
        }
    }
}
