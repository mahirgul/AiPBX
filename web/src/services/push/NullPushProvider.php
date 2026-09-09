<?php

namespace App\Services\Push;

require_once __DIR__ . '/PushProviderInterface.php';

class NullPushProvider implements PushProviderInterface
{
    public function getIdentifier(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function sendToExtension(string $extension, array $payload = []): array
    {
        return [
            'success' => false,
            'delivered' => 0,
            'failed' => 0,
            'errors' => ['Push provider is none or push is disabled.']
        ];
    }

    public function sendToToken(string $token, array $payload = []): array
    {
        return [
            'success' => false,
            'message' => 'Push provider is none or push is disabled.',
            'error' => 'PROVIDER_NONE'
        ];
    }
}
