import UIKit
import UserNotifications

public class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    public func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        Task { @MainActor in
            ChatNotifications.shared.registerCategory()
        }

        // PushKit first: a VoIP push that launched the app is delivered only
        // after the registry exists. Then sign back in with the saved session.
        PushRegistrationManager.shared.start()
        Task { @MainActor in
            await AppState.shared.restoreSessionIfNeeded()
        }

        // Request Push Notification Authorization
        UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound]) { granted, error in
            if granted {
                DispatchQueue.main.async {
                    application.registerForRemoteNotifications()
                }
                AppLogManager.shared.info("AppDelegate", "Remote notifications permission granted")
            } else if let error = error {
                AppLogManager.shared.warn("AppDelegate", "Push auth error: \(error.localizedDescription)")
            }
        }

        return true
    }

    public func application(
        _ application: UIApplication,
        didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
    ) {
        PushRegistrationManager.shared.didReceiveApnsToken(deviceToken)
    }

    public func application(
        _ application: UIApplication,
        didFailToRegisterForRemoteNotificationsWithError error: Error
    ) {
        AppLogManager.shared.warn("AppDelegate", "Failed to register for remote notifications: \(error.localizedDescription)")
    }

    public func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        // While the app is open the live chat connection already posts the
        // message notification; the server's push for it would be a duplicate.
        let info = notification.request.content.userInfo
        let isRemote = notification.request.trigger is UNPushNotificationTrigger
        let action = info["action"] as? String ?? ""
        if isRemote, ["new_message", "group_created", "group_member_added"].contains(action),
           ChatWebSocketManager.shared.isConnected {
            completionHandler([])
            return
        }
        completionHandler([.banner, .sound, .badge])
    }

    /// The Reply field of a chat notification: sent without opening the app.
    public func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        guard response.actionIdentifier == ChatNotifications.replyActionId,
              let textResponse = response as? UNTextInputNotificationResponse else {
            completionHandler()
            return
        }
        Task { @MainActor in
            await ChatNotifications.shared.handleReply(textResponse)
            completionHandler()
        }
    }
}
