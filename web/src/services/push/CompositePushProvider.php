<?php

namespace App\Services\Push;

require_once __DIR__ . '/PushProviderInterface.php';

/**
 * Sends through every enabled channel at once (FCM for Android, APNs for iOS).
 * Each provider only picks the devices registered for it (push_type), so an
 * extension with both an Android and an iPhone gets the push on both.
 */
class CompositePushProvider implements PushProviderInterface
{
    /** @var PushProviderInterface[] */
    private array $providers;

    /**
     * @param PushProviderInterface[] $providers
     */
    public function __construct(array $providers)
    {
        $this->providers = array_values($providers);
    }

    public function getIdentifier(): string
    {
        return implode('+', array_map(fn(PushProviderInterface $p) => $p->getIdentifier(), $this->providers));
    }

    public function isConfigured(): bool
    {
        foreach ($this->providers as $p) {
            if ($p->isConfigured()) {
                return true;
            }
        }
        return false;
    }

    public function sendToExtension(string $extension, array $payload = []): array
    {
        $delivered = 0;
        $failed = 0;
        $errors = [];
        foreach ($this->providers as $p) {
            if (!$p->isConfigured()) {
                continue;
            }
            $res = $p->sendToExtension($extension, $payload);
            $delivered += (int)($res['delivered'] ?? 0);
            $failed += (int)($res['failed'] ?? 0);
            foreach ($res['errors'] ?? [] as $err) {
                $errors[] = strtoupper($p->getIdentifier()) . ': ' . $err;
            }
        }

        return [
            'success' => $delivered > 0,
            'delivered' => $delivered,
            'failed' => $failed,
            'errors' => $errors
        ];
    }

    /**
     * A raw token goes to APNs when it has the APNs shape (64 hex chars),
     * otherwise to the first other provider (FCM).
     */
    public function sendToToken(string $token, array $payload = []): array
    {
        $wantApns = ApnsPushProvider::looksLikeApnsToken(trim($token));
        foreach ($this->providers as $p) {
            if (($p->getIdentifier() === 'apns') === $wantApns && $p->isConfigured()) {
                return $p->sendToToken($token, $payload);
            }
        }
        return [
            'success' => false,
            'message' => 'No configured push provider for this token.',
            'error' => 'NOT_CONFIGURED'
        ];
    }
}
