<?php

namespace App\Services\Push;

interface PushProviderInterface
{
    /**
     * Provider unique identifier ('none', 'fcm', etc.)
     */
    public function getIdentifier(): string;

    /**
     * Check if this provider is actively configured and ready to send.
     */
    public function isConfigured(): bool;

    /**
     * Send a wake-up push notification to an extension's active mobile devices.
     *
     * @param string $extension
     * @param array $payload
     * @return array ['success' => bool, 'delivered' => int, 'failed' => int, 'errors' => array]
     */
    public function sendToExtension(string $extension, array $payload = []): array;

    /**
     * Send a direct push notification to a specific device token.
     *
     * @param string $token
     * @param array $payload
     * @return array ['success' => bool, 'message' => string, 'error' => ?string]
     */
    public function sendToToken(string $token, array $payload = []): array;
}
