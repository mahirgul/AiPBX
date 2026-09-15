import SwiftUI

public struct KeypadButtonModel {
    public let digit: String
    public let letters: String
}

public struct DialKeypadView: View {
    public var onDigitTapped: (String) -> Void

    private let keypadGrid: [[KeypadButtonModel]] = [
        [
            KeypadButtonModel(digit: "1", letters: ""),
            KeypadButtonModel(digit: "2", letters: "ABC"),
            KeypadButtonModel(digit: "3", letters: "DEF")
        ],
        [
            KeypadButtonModel(digit: "4", letters: "GHI"),
            KeypadButtonModel(digit: "5", letters: "JKL"),
            KeypadButtonModel(digit: "6", letters: "MNO")
        ],
        [
            KeypadButtonModel(digit: "7", letters: "PQRS"),
            KeypadButtonModel(digit: "8", letters: "TUV"),
            KeypadButtonModel(digit: "9", letters: "WXYZ")
        ],
        [
            KeypadButtonModel(digit: "*", letters: ""),
            KeypadButtonModel(digit: "0", letters: "+"),
            KeypadButtonModel(digit: "#", letters: "")
        ]
    ]

    public init(onDigitTapped: @escaping (String) -> Void) {
        self.onDigitTapped = onDigitTapped
    }

    public var body: some View {
        VStack(spacing: 14) {
            ForEach(0..<keypadGrid.count, id: \.self) { row in
                HStack(spacing: 24) {
                    ForEach(0..<self.keypadGrid[row].count, id: \.self) { col in
                        let item = self.keypadGrid[row][col]
                        Button(action: {
                            onDigitTapped(item.digit)
                        }) {
                            VStack(spacing: 2) {
                                Text(item.digit)
                                    .font(.system(size: 28, weight: .regular))
                                    .foregroundColor(.primary)

                                if !item.letters.isEmpty {
                                    Text(item.letters)
                                        .font(.system(size: 10, weight: .bold))
                                        .foregroundColor(.secondary)
                                } else {
                                    Text(" ")
                                        .font(.system(size: 10))
                                }
                            }
                            .frame(width: 72, height: 72)
                            .background(Color(.systemGray6))
                            .clipShape(Circle())
                        }
                        .buttonStyle(KeypadButtonStyle())
                    }
                }
            }
        }
    }
}

public struct KeypadButtonStyle: ButtonStyle {
    public func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? 0.92 : 1.0)
            .opacity(configuration.isPressed ? 0.8 : 1.0)
            .animation(.easeInOut(duration: 0.1), value: configuration.isPressed)
    }
}
