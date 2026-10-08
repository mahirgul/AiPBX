<?php
require_once __DIR__ . '/PhoneTemplate.php';

/**
 * Fanvil X series: <mac>.cfg (lowercase MAC) in Fanvil's sectioned text
 * format (<<VOIP CONFIG FILE>> … <<END OF FILE>>).
 *
 * Function keys: Fkey<n> Type 1 (memory key) with "<number>@<line>/<subtype>"
 * (b = BLF, s = speed dial, c = call park), Type 2 = line, Type 3 = key event
 * (F_DND). Expansion modules are not written yet (docs/phones.md).
 */
final class FanvilTemplate extends PhoneTemplate
{
    private const LANGUAGES = ['en', 'tr', 'de', 'fr', 'es', 'it', 'pt', 'ru', 'pl', 'nl', 'cs', 'uk', 'hu'];

    public function vendor(): string
    {
        return 'fanvil';
    }

    public function macFromFile(string $file): string
    {
        return preg_match('/^([0-9a-f]{12})\.cfg$/i', $file, $m) ? PhoneModels::normalizeMac($m[1]) : '';
    }

    public function deviceFile(string $mac): string
    {
        return $mac . '.cfg';
    }

    public function contentType(): string
    {
        return 'text/plain; charset=utf-8';
    }

    public function notifyResync(): string
    {
        return 'fanvil-check-cfg';
    }

    public function notifyReboot(): string
    {
        return 'fanvil-reboot';
    }

    public function render(array $ctx): string
    {
        $tls = $ctx['transport'] === 'tls';
        $ext = self::clean($ctx['extension']);
        $l = [
            '<<VOIP CONFIG FILE>>Version:2.0002',
            '',
            '<GLOBAL CONFIG MODULE>',
            'Enable SNTP        :1',
            'SNTP Server        :' . self::clean($ctx['ntp'], 255),
            // Minutes east of UTC.
            'Time Zone          :' . (int) $ctx['utc_offset'],
            'Language           :' . (in_array($ctx['language'], self::LANGUAGES, true) ? $ctx['language'] : 'en'),
            '',
            '<SIP CONFIG MODULE>',
            '--SIP Line List--  :',
            'SIP1 Phone Number  :' . $ext,
            'SIP1 Display Name  :' . self::clean($ctx['display_name']),
            'SIP1 Sip Name      :' . $ext,
            'SIP1 Register Addr :' . self::clean($ctx['server'], 255),
            'SIP1 Register Port :' . (int) $ctx['port'],
            'SIP1 Register User :' . $ext,
            'SIP1 Register Pswd :' . self::clean($ctx['sip_password'], 128),
            'SIP1 Register TTL  :1800',
            'SIP1 Enable Reg    :1',
            'SIP1 Transport     :' . ($tls ? 2 : 0),
            'SIP1 Enable SRTP   :' . ((int) $ctx['srtp'] > 0 ? 1 : 0),
            'SIP1 MWI Num       :' . self::clean($ctx['voicemail']),
            'SIP1 BLF Pickup Num:' . self::clean($ctx['pickup_prefix']),
            '',
            '<MMI CONFIG MODULE>',
            '--MMI Account--    :',
            'Account1 Name      :admin',
            'Account1 Password  :' . self::clean($ctx['admin_password'], 128),
            'Account1 Level     :10',
        ];

        if (!empty($ctx['keys'])) {
            $model = PhoneModels::get($ctx['model']) ?? ['keys' => 0];
            $keys = self::keysByPage($ctx['keys'], 0);
            $l[] = '';
            $l[] = '<DSS CONFIG MODULE>';
            for ($pos = 1; $pos <= $model['keys']; $pos++) {
                [$type, $value] = self::keyValue($keys[$pos] ?? null);
                $l[] = "Fkey{$pos} Type :{$type}";
                $l[] = "Fkey{$pos} Value :{$value}";
                $l[] = "Fkey{$pos} Title :" . ($type === 0 ? '' : self::clean($keys[$pos]['label'] ?? ''));
            }
        }

        $l[] = '';
        $l[] = '<<END OF FILE>>';
        return implode("\n", $l) . "\n";
    }

    /** @return array{0: int, 1: string} Fkey type and value */
    private static function keyValue(?array $k): array
    {
        if ($k === null) {
            return [0, ''];
        }
        $target = self::clean($k['target']);
        return match ($k['type']) {
            'blf' => [1, "{$target}@1/b"],
            'speeddial' => [1, "{$target}@1/s"],
            'park' => [1, "{$target}@1/c"],
            'line' => [2, 'SIP1'],
            'dnd' => [3, 'F_DND'],
            default => [0, ''],
        };
    }
}
