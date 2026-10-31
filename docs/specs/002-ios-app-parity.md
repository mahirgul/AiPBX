# Spec 002: iOS Mobile App Feature Parity & In-App Media Viewer

- **Status:** Implemented
- **Author:** Mahir Gül
- **Target Release:** v1.50.35
- **Date:** 2026-10-31

---

## 1. Problem & Goal (What & Why)

### 1.1 Problem Statement
The native iOS mobile client ([`AiPBX-ios`](https://github.com/mahirgul/AiPBX-ios)) has achieved WebRTC video calling, PushKit VoIP notifications, 23-language localization, CallKit integration, swipe-to-delete call history, and notification inline reply. However:
1. In chat, tapping a photo currently invokes `UIApplication.shared.open(url)`, ejecting the user out of AiPBX into Mobile Safari instead of opening an in-app full-screen photo viewer (unlike Android's `ImageViewerDialog`).
2. Version tags and build numbers across `AiPBX-ios` and `AiPBX-Android` need synchronization with the core PBX repository (`v1.50.35`).
3. The project roadmap ([`docs/roadmap.md`](../roadmap.md)) still lists iOS video calls and parity features as open, requiring reconciliation.

### 1.2 Proposed Solution
1. **In-App Image Viewer (`ImageViewerOverlay` / `ImageViewerView`):**
   Implement a native SwiftUI full-screen modal viewer with pinch-to-zoom (`MagnificationGesture`), pan (`DragGesture`), double-tap to reset/zoom, and tap/swipe to dismiss.
2. **Version Synchronization:**
   Update `VERSION` across `AiPBX-ios` and `AiPBX-Android` to `1.50.35`, updating build numbers and version strings in `project.pbxproj` and `LoginView.swift`.
3. **Roadmap & Documentation Reconciliation:**
   Update `docs/roadmap.md` to reflect that video calls and iOS app parity are fully implemented and verified.

---

## 2. User Stories & Acceptance Criteria (DoD)

### User Stories
- As an **iOS user**, I want to tap on a photo in chat and view it full-screen with pinch-to-zoom inside the app, so I don't get kicked out into Safari.
- As an **Administrator**, I want the iOS and Android apps to report the unified version number matching the AiPBX core release (`v1.50.35`).

### Acceptance Criteria (Definition of Done)
- [x] **AC-1:** Tapping an image in `ChatMessageBubbleView.swift` opens an in-app full-screen viewer overlay rather than Safari.
- [x] **AC-2:** The viewer supports pinch-to-zoom (magnification), dragging (pan) when zoomed, double-tap zoom/reset, and tap/close button dismiss.
- [x] **AC-3:** Version strings and `VERSION` files in `AiPBX-ios` and `AiPBX-Android` bumped to `1.50.35`.
- [x] **AC-4:** 100% 23-language string parity maintained in `AiPBX-ios` (0 missing keys).
- [x] **AC-5:** `docs/roadmap.md` updated to mark iOS video calls and feature parity as completed.

---

## 3. Technical Design (How)

### 3.1 In-App Image Viewer Component
File: `AiPBX-ios/AiPBX/Views/Components/ImageViewerOverlay.swift`
- Full-screen black background modal (`ZStack`) with safe area ignored.
- Supports `scale` (1.0 to 4.0) via `@GestureState` / `@State` magnification.
- Supports `offset` (CGSize) via drag gesture when `scale > 1.0`.
- Top navigation bar overlay with close button (`xmark.circle.fill`), image title, and share button (`UIActivityViewController`).
- In `ChatRoomView.swift` or `ChatMessageBubbleView.swift`, binds `@State private var selectedImageUrl: URL?` to display the overlay when tapped.

### 3.2 Version Synchronization
- `AiPBX-ios/VERSION`: `1.50.35`
- `AiPBX-ios/AiPBX.xcodeproj/project.pbxproj`: `MARKETING_VERSION = 1.50.35; CURRENT_PROJECT_VERSION = 75;`
- `AiPBX-ios/AiPBX/Views/LoginView.swift`: Update default fallback version to `1.50.35`.
- `AiPBX-Android/VERSION`: `1.50.35`

---

## 4. Implementation Tasks & Checklist

- [x] **Phase 1: Build Native SwiftUI Image Viewer for iOS**
  - [x] Create `AiPBX-ios/AiPBX/Views/Components/ImageViewerOverlay.swift`
  - [x] Connect image tap in `ChatMessageBubbleView.swift` / `ChatRoomView.swift`
- [x] **Phase 2: Version Bump & Parity Alignment**
  - [x] Bump `AiPBX-ios/VERSION`, `project.pbxproj`, and `LoginView.swift` to `1.50.35`
  - [x] Bump `AiPBX-Android/VERSION` to `1.50.35`
- [x] **Phase 3: Roadmap & Index Updates**
  - [x] Update `docs/roadmap.md` in `AiPBX`
  - [x] Update `docs/specs/README.md` index
