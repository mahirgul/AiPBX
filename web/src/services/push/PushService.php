<?php

namespace App\Services\Push;

require_once __DIR__ . '/PushProviderInterface.php';
require_once __DIR__ . '/NullPushProvider.php';
require_once __DIR__ . '/FcmPushProvider.php';
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

        $enabled = (string)getSystemSetting('push_enabled', '0');
        $providerType = (string)getSystemSetting('push_provider', 'none');

        if ($enabled !== '1' || $providerType === 'none') {
            self::$providerInstance = new NullPushProvider();
            return self::$providerInstance;
        }

        if ($providerType === 'fcm') {
            self::$providerInstance = new FcmPushProvider();
            return self::$providerInstance;
        }

        // Default fallback to Null
        self::$providerInstance = new NullPushProvider();
        return self::$providerInstance;
    }

    /**
     * Override provider instance for unit tests.
     */
    public static function setProvider(?PushProviderInterface $provider): void
    {
        self::$providerInstance = $provider;
    }
}
