import SwiftUI

public struct CallRecordRowView: View {
    public let call: CallRecord
    public let onCallBack: () -> Void

    public init(call: CallRecord, onCallBack: @escaping () -> Void) {
        self.call = call
        self.onCallBack = onCallBack
    }

    private var directionIcon: (name: String, color: Color) {
        switch call.direction.lowercased() {
        case "missed":
            return ("phone.arrow.down.left.fill", .red)
        case "in":
            return ("phone.arrow.down.left", .green)
        case "out":
            return ("phone.arrow.up.right", .blue)
        default:
            return ("phone.fill", .secondary)
        }
    }

    public var body: some View {
        HStack(spacing: 14) {
            // Direction Icon Circle
            ZStack {
                Circle()
                    .fill(directionIcon.color.opacity(0.12))
                    .frame(width: 42, height: 42)

                Image(systemName: directionIcon.name)
                    .font(.system(size: 18))
                    .foregroundColor(directionIcon.color)
            }

            // Party Name and Details
            VStack(alignment: .leading, spacing: 4) {
                Text(call.effectiveName)
                    .font(.system(size: 16, weight: .semibold))
                    .foregroundColor(call.direction.lowercased() == "missed" ? .red : .primary)
                    .lineLimit(1)

                HStack(spacing: 6) {
                    Text(call.party)
                        .font(.caption)
                        .foregroundColor(.secondary)

                    Text("•")
                        .font(.caption2)
                        .foregroundColor(.secondary)

                    Text(call.calldate)
                        .font(.caption)
                        .foregroundColor(.secondary)
                }
            }

            Spacer()

            // Duration & Callback
            VStack(alignment: .trailing, spacing: 4) {
                if call.billsec > 0 {
                    Text(call.formattedDuration)
                        .font(.caption.monospacedDigit())
                        .foregroundColor(.secondary)
                }

                Button(action: onCallBack) {
                    Image(systemName: "phone.circle.fill")
                        .font(.system(size: 26))
                        .foregroundColor(.green)
                }
                .buttonStyle(BorderlessButtonStyle())
            }
        }
        .padding(.vertical, 4)
    }
}
