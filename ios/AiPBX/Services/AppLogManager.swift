import Foundation
import Combine

public final class AppLogManager: ObservableObject {
    public static let shared = AppLogManager()

    @Published public private(set) var logs: [LogEntry] = []
    private let maxEntries = 1000
    private let queue = DispatchQueue(label: "com.mhrgl.aipbx.logqueue", qos: .utility)
    private let dateFormatter: DateFormatter

    public struct LogEntry: Identifiable, Hashable {
        public let id = UUID()
        public let timestamp: Date
        public let tag: String
        public let message: String
        public let level: Level

        public enum Level: String {
            case debug = "DEBUG"
            case info = "INFO"
            case warn = "WARN"
            case error = "ERROR"
        }

        public func formatted(with formatter: DateFormatter) -> String {
            return "[\(formatter.string(from: timestamp))] [\(level.rawValue)] [\(tag)] \(message)"
        }
    }

    private init() {
        let df = DateFormatter()
        df.dateFormat = "yyyy-MM-dd HH:mm:ss.SSS"
        df.locale = Locale(identifier: "en_US_POSIX")
        self.dateFormatter = df
    }

    public func log(_ tag: String, _ message: String, level: LogEntry.Level = .info) {
        let entry = LogEntry(timestamp: Date(), tag: tag, message: message, level: level)
        #if DEBUG
        print("[\(entry.level.rawValue)] [\(tag)] \(message)")
        #endif

        queue.async {
            DispatchQueue.main.async {
                self.logs.append(entry)
                if self.logs.count > self.maxEntries {
                    self.logs.removeFirst(self.logs.count - self.maxEntries)
                }
            }
        }
    }

    public func debug(_ tag: String, _ message: String) {
        log(tag, message, level: .debug)
    }

    public func info(_ tag: String, _ message: String) {
        log(tag, message, level: .info)
    }

    public func warn(_ tag: String, _ message: String) {
        log(tag, message, level: .warn)
    }

    public func error(_ tag: String, _ message: String) {
        log(tag, message, level: .error)
    }

    public func clear() {
        DispatchQueue.main.async {
            self.logs.removeAll()
        }
    }

    public func exportLogsAsText() -> String {
        return logs.map { $0.formatted(with: dateFormatter) }.joined(separator: "\n")
    }
}
