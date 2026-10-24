<?php
/**
 * Night dialling protection (Dial permissions page): outside office hours,
 * numbers starting with a protected prefix (international, premium) are
 * blocked or need a night PIN. Toll fraud through a stolen account or a
 * transit trunk usually happens at night and to such numbers.
 *
 * Checked in [sub-check-dial-permission] (SyncPermissions) before the
 * permission groups, so it covers every outbound route call: extensions,
 * click-to-call and trunk-to-trunk transit. Portal faxes are exempt.
 */
final class NightGuardService
{
    public const DEFAULTS = [
        'night_guard_enabled' => '0',
        'night_guard_from' => '20:00',
        'night_guard_to' => '08:00',
        'night_guard_weekend' => '1',
        'night_guard_prefixes' => '00,0900',
        'night_guard_action' => 'block',
        'night_guard_pin' => '',
    ];

    /** @return array<string, string> */
    public static function settings(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $def) {
            $out[$k] = (string) getSystemSetting($k, $def);
        }
        return $out;
    }

    /** @return list<string> digits, + allowed */
    public static function prefixes(string $list): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $list) ?: [] as $p) {
            $p = preg_replace('/[^0-9+]/', '', $p);
            if ($p !== '' && strlen($p) <= 15) {
                $out[$p] = true;
            }
        }
        return array_keys($out);
    }

    public static function save(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $time = fn($v, $def) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? (string) $v : $def;
        $values = [
            'night_guard_enabled' => !empty($post['night_guard_enabled']) ? '1' : '0',
            'night_guard_from' => $time($post['night_guard_from'] ?? '', '20:00'),
            'night_guard_to' => $time($post['night_guard_to'] ?? '', '08:00'),
            'night_guard_weekend' => !empty($post['night_guard_weekend']) ? '1' : '0',
            'night_guard_prefixes' => implode(',', self::prefixes((string) ($post['night_guard_prefixes'] ?? ''))),
            'night_guard_action' => ($post['night_guard_action'] ?? 'block') === 'pin' ? 'pin' : 'block',
        ];
        // An empty PIN field keeps the PIN that is set.
        $pin = preg_replace('/\D/', '', (string) ($post['night_guard_pin'] ?? ''));
        $values['night_guard_pin'] = $pin !== '' ? $pin : (string) getSystemSetting('night_guard_pin', '');
        if ($values['night_guard_enabled'] === '1' && $values['night_guard_prefixes'] === '') {
            return ['success' => false, 'error' => t('night_guard.err_prefixes')];
        }
        if ($values['night_guard_action'] === 'pin' && strlen($values['night_guard_pin']) < 4) {
            return ['success' => false, 'error' => t('night_guard.err_pin')];
        }
        $st = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($values as $k => $v) {
            $st->execute([$k, $v]);
        }
        markPendingSync('permissions', 'night_guard', 0, 'Night dialling protection: ' . ($values['night_guard_enabled'] === '1' ? 'on' : 'off'), 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('night_guard.saved')];
    }

    /**
     * [sub-night-guard] for the permissions dialplan; ARG1 is the dialled
     * number. Empty string when the protection is off.
     */
    public static function dialplan(): string
    {
        $s = self::settings();
        $prefixes = self::prefixes($s['night_guard_prefixes']);
        if ($s['night_guard_enabled'] !== '1' || !$prefixes) {
            return '';
        }
        [$from, $to] = [$s['night_guard_from'], $s['night_guard_to']];
        $c = "; Night dialling protection (Dial permissions page)\n";
        $c .= "[sub-night-guard]\n";
        $c .= "exten => s,1,GotoIf(\$[\"\${AIPBX_FAX}\" = \"1\"]?ok)\n";
        // Night: from-to, crossing midnight when from > to; plus all weekend when set.
        if ($from > $to) {
            $c .= " same => n,GotoIfTime({$from}-23:59,*,*,*?night)\n";
            $c .= " same => n,GotoIfTime(00:00-{$to},*,*,*?night)\n";
        } else {
            $c .= " same => n,GotoIfTime({$from}-{$to},*,*,*?night)\n";
        }
        if ($s['night_guard_weekend'] === '1') {
            $c .= " same => n,GotoIfTime(*,sat-sun,*,*?night)\n";
        }
        $c .= " same => n(ok),Return()\n";
        $c .= " same => n(night),NoOp(Night protection: checking \${ARG1})\n";
        foreach ($prefixes as $p) {
            $len = strlen($p);
            $c .= " same => n,GotoIf(\$[\"\${ARG1:0:{$len}}\" = \"{$p}\"]?guard)\n";
        }
        $c .= " same => n,Return()\n";
        $c .= " same => n(guard),Set(CDR(userfield)=NIGHT_GUARD)\n";
        if ($s['night_guard_action'] === 'pin' && strlen($s['night_guard_pin']) >= 4) {
            // Three tries ("Please enter your password, then press pound"), then hang up.
            $c .= " same => n,Answer()\n";
            $c .= " same => n,Authenticate({$s['night_guard_pin']})\n";
            $c .= " same => n,Return()\n\n";
        } else {
            // Early media: the call is not answered, so nobody is billed for the refusal.
            $c .= " same => n,Progress()\n";
            $c .= " same => n,Playback(cannot-complete-as-dialed,noanswer)\n";
            $c .= " same => n,Hangup(21)\n\n";
        }
        return $c;
    }
}
