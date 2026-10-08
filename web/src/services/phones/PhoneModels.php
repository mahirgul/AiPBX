<?php
/**
 * Desk phone models known to the provisioning page and their key counts.
 *
 * keys:     programmable keys on the phone itself (all pages of the screen).
 * exp_keys: keys per expansion module (0 = no module written for this model).
 * exp_max:  how many expansion modules the model takes.
 *
 * A model added here shows up in the forms and gets its vendor's template;
 * a new vendor also needs a PhoneTemplate subclass (see PhoneTemplates::forVendor()).
 */
final class PhoneModels
{
    public const MODELS = [
        // Yealink (EXP43: 20 keys x 3 pages per module)
        'yealink-t31g' => ['vendor' => 'yealink', 'label' => 'Yealink T31G', 'keys' => 2, 'exp_keys' => 0, 'exp_max' => 0],
        'yealink-t33g' => ['vendor' => 'yealink', 'label' => 'Yealink T33G', 'keys' => 4, 'exp_keys' => 0, 'exp_max' => 0],
        'yealink-t43u' => ['vendor' => 'yealink', 'label' => 'Yealink T43U', 'keys' => 21, 'exp_keys' => 0, 'exp_max' => 0],
        'yealink-t46u' => ['vendor' => 'yealink', 'label' => 'Yealink T46U', 'keys' => 27, 'exp_keys' => 60, 'exp_max' => 3],
        'yealink-t48u' => ['vendor' => 'yealink', 'label' => 'Yealink T48U', 'keys' => 29, 'exp_keys' => 60, 'exp_max' => 3],
        'yealink-t53w' => ['vendor' => 'yealink', 'label' => 'Yealink T53W', 'keys' => 21, 'exp_keys' => 0, 'exp_max' => 0],
        'yealink-t54w' => ['vendor' => 'yealink', 'label' => 'Yealink T54W', 'keys' => 27, 'exp_keys' => 60, 'exp_max' => 3],
        'yealink-t57w' => ['vendor' => 'yealink', 'label' => 'Yealink T57W', 'keys' => 29, 'exp_keys' => 60, 'exp_max' => 3],
        // Grandstream (multi-purpose keys; GXP2200EXT not written yet)
        'grandstream-grp2612' => ['vendor' => 'grandstream', 'label' => 'Grandstream GRP2612', 'keys' => 2, 'exp_keys' => 0, 'exp_max' => 0],
        'grandstream-grp2614' => ['vendor' => 'grandstream', 'label' => 'Grandstream GRP2614', 'keys' => 4, 'exp_keys' => 0, 'exp_max' => 0],
        'grandstream-gxp2135' => ['vendor' => 'grandstream', 'label' => 'Grandstream GXP2135', 'keys' => 8, 'exp_keys' => 0, 'exp_max' => 0],
        'grandstream-gxp2160' => ['vendor' => 'grandstream', 'label' => 'Grandstream GXP2160', 'keys' => 24, 'exp_keys' => 0, 'exp_max' => 0],
        'grandstream-gxp2170' => ['vendor' => 'grandstream', 'label' => 'Grandstream GXP2170', 'keys' => 48, 'exp_keys' => 0, 'exp_max' => 0],
        // Fanvil (DSS keys; expansion modules not written yet)
        'fanvil-x3u' => ['vendor' => 'fanvil', 'label' => 'Fanvil X3U', 'keys' => 6, 'exp_keys' => 0, 'exp_max' => 0],
        'fanvil-x4u' => ['vendor' => 'fanvil', 'label' => 'Fanvil X4U', 'keys' => 30, 'exp_keys' => 0, 'exp_max' => 0],
        'fanvil-x5u' => ['vendor' => 'fanvil', 'label' => 'Fanvil X5U', 'keys' => 48, 'exp_keys' => 0, 'exp_max' => 0],
        'fanvil-x6u' => ['vendor' => 'fanvil', 'label' => 'Fanvil X6U', 'keys' => 60, 'exp_keys' => 0, 'exp_max' => 0],
    ];

    public const VENDORS = ['yealink' => 'Yealink', 'grandstream' => 'Grandstream', 'fanvil' => 'Fanvil'];

    /** MAC prefixes (OUI) per vendor, for phones that ask without a useful User-Agent. */
    private const OUI = [
        'yealink' => ['001565', '805ec0', '249ad8', '44dbd2', '805e0c', 'c4fc22'],
        'grandstream' => ['000b82', 'c074ad', 'ec74d7'],
        'fanvil' => ['0c383e', '7c2f80', '0c1105'],
    ];

    public static function get(string $model): ?array
    {
        return self::MODELS[$model] ?? null;
    }

    public static function vendorOf(string $model): string
    {
        return self::MODELS[$model]['vendor'] ?? '';
    }

    /** Number of key pages: the phone itself plus its expansion modules. */
    public static function pageCount(string $model): int
    {
        $m = self::get($model);
        return $m === null ? 0 : 1 + ($m['exp_keys'] > 0 ? $m['exp_max'] : 0);
    }

    /** Keys on page 0 (the phone) or on one expansion module page. */
    public static function keysOnPage(string $model, int $page): int
    {
        $m = self::get($model);
        if ($m === null || $page < 0 || $page >= self::pageCount($model)) {
            return 0;
        }
        return $page === 0 ? $m['keys'] : $m['exp_keys'];
    }

    /** Vendor of an unknown phone: its User-Agent first, the MAC prefix second ('' when unsure). */
    public static function guessVendor(string $mac, string $userAgent): string
    {
        foreach (array_keys(self::VENDORS) as $vendor) {
            if (stripos($userAgent, $vendor) !== false) {
                return $vendor;
            }
        }
        $prefix = substr(strtolower($mac), 0, 6);
        foreach (self::OUI as $vendor => $prefixes) {
            if (in_array($prefix, $prefixes, true)) {
                return $vendor;
            }
        }
        return '';
    }

    /** Lowercase 12-digit MAC, or '' when $raw is not a MAC (separators are dropped). */
    public static function normalizeMac(string $raw): string
    {
        $mac = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $raw));
        return strlen($mac) === 12 && $mac !== '000000000000' && $mac !== 'ffffffffffff' ? $mac : '';
    }

    /** 001565aabbcc → 00:15:65:AA:BB:CC */
    public static function formatMac(string $mac): string
    {
        return strtoupper(implode(':', str_split($mac, 2)));
    }
}
