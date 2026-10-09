<?php

namespace App\Services\Push;

require_once __DIR__ . '/PushProviderInterface.php';
require_once __DIR__ . '/NullPushProvider.php';
require_once __DIR__ . '/FcmPushProvider.php';
require_once __DIR__ . '/ApnsPushProvider.php';
require_once __DIR__ . '/CompositePushProvider.php';
require_once __DIR__ . '/../../../config.php';

class PushService
{
    private static ?PushProviderInterface $providerInstance = null;

    /**
     * Get active push provider according to system settings.
     */
    public static function getProvider(): PushProviderInterface
    {
        if (self::$providerInstance !== null) {
            return self::$providerInstance;
        }

        if (!self::isEnabled()) {
            self::$providerInstance = new NullPushProvider();
            return self::$providerInstance;
        }

        // Android (FCM) and iOS (APNs) are independent channels: both can be on.
        $providers = [];
        if ((string)getSystemSetting('push_provider', 'none') === 'fcm') {
            $providers[] = new FcmPushProvider();
        }
        if ((string)getSystemSetting('push_apns_enabled', '0') === '1') {
            $providers[] = new ApnsPushProvider();
        }

        if ($providers === []) {
            self::$providerInstance = new NullPushProvider();
        } elseif (count($providers) === 1) {
            self::$providerInstance = $providers[0];
        } else {
            self::$providerInstance = new CompositePushProvider($providers);
        }
        return self::$providerInstance;
    }

    /**
     * The push layer is on and at least one channel (FCM or APNs) is selected.
     * The dialplan uses it to decide whether to wake mobile devices.
     */
    public static function isEnabled(): bool
    {
        if ((string)getSystemSetting('push_enabled', '0') !== '1') {
            return false;
        }
        return (string)getSystemSetting('push_provider', 'none') !== 'none'
            || (string)getSystemSetting('push_apns_enabled', '0') === '1';
    }

    /**
     * Override provider instance for unit tests.
     */
    public static function setProvider(?PushProviderInterface $provider): void
    {
        self::$providerInstance = $provider;
    }
}
