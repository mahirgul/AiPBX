<?php
/**
 * Voicemail limits and PIN rules (PBX settings → Voicemail).
 *
 * The limits go to voicemail.conf [general] through SyncVoicemail
 * (voicemailGeneralConf); the PIN rules are checked wherever a PIN is saved
 * (extension form, My phone) and, for a PIN changed from a phone, by
 * Asterisk's own minpassword.
 */
final class VoicemailPolicy
{
    /** key => [default, min, max] */
    public const LIMITS = [
        // Defaults: minsecs=3 and maxsilence=2 ensure Asterisk does not emit
        // "maxsilence should be less than minsecs or you may get empty messages" warning.
        'vm_max_secs' => [0, 0, 3600],        // longest message (s, 0 = no limit)
        'vm_min_secs' => [3, 0, 60],          // shorter messages are not kept (s)
        'vm_max_msg' => [100, 10, 9999],      // messages per mailbox
        'vm_max_silence' => [2, 0, 60],       // silence that ends a message (s, 0 = off)
        'vm_max_logins' => [3, 1, 10],        // wrong PINs before the call is ended
        'vm_pin_min' => [4, 4, 10],           // shortest PIN
    ];

    /** @return array<string, int|bool> */
    public static function settings(): array
    {
        $out = [];
        foreach (self::LIMITS as $k => [$def, $min, $max]) {
            $out[$k] = max($min, min($max, (int) getSystemSetting($k, (string) $def)));
        }
        $out['vm_pin_not_extension'] = getSystemSetting('vm_pin_not_extension', '1') === '1';
        return $out;
    }

    /** Settings from a form, clamped. @return array<string, string> */
    public static function fromPost(array $post): array
    {
        $out = [];
        foreach (self::LIMITS as $k => [$def, $min, $max]) {
            $out[$k] = (string) max($min, min($max, (int) ($post[$k] ?? $def)));
        }
        $out['vm_pin_not_extension'] = !empty($post['vm_pin_not_extension']) ? '1' : '0';
        return $out;
    }

    /** Null when the PIN may be used, else the reason (translated). */
    public static function pinError(string $pin, string $extension): ?string
    {
        $s = self::settings();
        if (!preg_match('/^\d+$/', $pin) || strlen($pin) < $s['vm_pin_min']) {
            return sprintf(t('voicemail_policy.err_pin_short'), $s['vm_pin_min']);
        }
        if ($s['vm_pin_not_extension'] && $pin === preg_replace('/\D/', '', $extension)) {
            return t('voicemail_policy.err_pin_extension');
        }
        return null;
    }

    /** A random PIN that passes the rules (for a mailbox saved without one). */
    public static function randomPin(string $extension): string
    {
        $len = max(6, self::settings()['vm_pin_min']);
        do {
            $pin = '';
            for ($i = 0; $i < $len; $i++) {
                $pin .= (string) random_int(0, 9);
            }
        } while (self::pinError($pin, $extension) !== null);
        return $pin;
    }

    /** Active mailboxes whose PIN does not pass the rules (shown on the settings page). */
    public static function weakPinCount(): int
    {
        $n = 0;
        $rows = getDB()->query("SELECT extension, voicemail_pin FROM sys_users WHERE is_active = 1 AND voicemail_enabled = 1 AND extension_type = 'sip' AND extension <> ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $pin = (string) $r['voicemail_pin'];
            if (self::pinError($pin !== '' ? $pin : (string) $r['extension'], (string) $r['extension']) !== null) {
                $n++;
            }
        }
        return $n;
    }

    /** The [general] lines for voicemail.conf. */
    public static function generalConf(): string
    {
        $s = self::settings();
        $minsecs = (int) $s['vm_min_secs'];
        $maxsilence = (int) $s['vm_max_silence'];
        // Asterisk app_voicemail warns if maxsilence > 0 and maxsilence >= minsecs:
        // "maxsilence should be less than minsecs or you may get empty messages"
        if ($maxsilence > 0 && $minsecs > 0 && $maxsilence >= $minsecs) {
            $maxsilence = max(1, $minsecs - 1);
        }
        return "maxsecs={$s['vm_max_secs']}\n"
            . "minsecs={$minsecs}\n"
            . "maxmsg={$s['vm_max_msg']}\n"
            . "maxsilence={$maxsilence}\n"
            . "maxlogins={$s['vm_max_logins']}\n"
            // A PIN changed from the phone (VoiceMailMain) must be this long too.
            . "minpassword={$s['vm_pin_min']}\n";
    }
}
