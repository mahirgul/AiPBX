<?php
require_once __DIR__ . '/PhoneModels.php';

/**
 * One vendor's provisioning file: which file names the phone asks for, and
 * the per-phone configuration rendered from a context array.
 *
 * Context keys (built by PhoneProvisionService::buildContext()):
 *   mac, model, extension, display_name, sip_password, server, port,
 *   transport ('udp'|'tls'), srtp (0 off, 1 optional, 2 required),
 *   codecs (list, preferred first: g722, pcma, pcmu, g729, opus), ntp,
 *   utc_offset (minutes east of UTC), language (UI code: en, tr, de …),
 *   voicemail (*97), pickup_prefix (*21), admin_password,
 *   keys: list of [page, position, type, target, label]
 *         (page 0 = the phone, 1..n = expansion modules; type blf,
 *         speeddial, park, dnd or line).
 *
 * To add a vendor: a subclass here, its models in
 * PhoneModels::MODELS, its notify events in asterisk-config/pjsip_notify.conf
 * and a case in PhoneTemplates::forVendor().
 */
abstract class PhoneTemplate
{
    abstract public function vendor(): string;

    /** MAC named by a per-phone file name of this vendor ('' when the name is not one). */
    abstract public function macFromFile(string $file): string;

    /** The per-phone file name the phone asks for first (shown on the page). */
    abstract public function deviceFile(string $mac): string;

    abstract public function contentType(): string;

    abstract public function render(array $ctx): string;

    /**
     * The answer to one per-phone file name. Most vendors have a single file;
     * a vendor with several (Poly: master file + settings file) overrides this.
     */
    public function renderFile(string $file, array $ctx): string
    {
        return $this->render($ctx);
    }

    /** pjsip_notify.conf section that makes the phone fetch its configuration again. */
    abstract public function notifyResync(): string;

    /** pjsip_notify.conf section that reboots the phone ('' when not supported). */
    abstract public function notifyReboot(): string;

    /**
     * A value written into a line-based file: no line breaks or other control
     * characters, so a display name or label cannot add settings of its own.
     */
    protected static function clean(string $value, int $max = 64): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        return mb_substr(trim($value), 0, $max);
    }

    /** A value for an XML element or attribute: cleaned like clean(), then escaped. */
    protected static function xml(string $value, int $max = 64): string
    {
        return htmlspecialchars(self::clean($value, $max), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Keys numbered across the phone and its expansion modules (1-based):
     * page 0 keeps its positions, module n follows the phone's keys and the
     * modules before it. For vendors that number all keys in one list.
     */
    protected static function keysFlat(array $keys, string $model): array
    {
        $m = PhoneModels::get($model) ?? ['keys' => 0, 'exp_keys' => 0];
        $out = [];
        foreach ($keys as $k) {
            $page = (int) $k['page'];
            $pos = (int) $k['position'];
            $n = $page === 0 ? $pos : $m['keys'] + ($page - 1) * $m['exp_keys'] + $pos;
            $out[$n] = $k;
        }
        ksort($out);
        return $out;
    }

    /** Number of keys on the phone plus all its expansion modules. */
    protected static function totalKeys(string $model): int
    {
        $m = PhoneModels::get($model) ?? ['keys' => 0, 'exp_keys' => 0, 'exp_max' => 0];
        return $m['keys'] + $m['exp_keys'] * $m['exp_max'];
    }

    /** Keys of one page, indexed by position. */
    protected static function keysByPage(array $keys, int $page): array
    {
        $out = [];
        foreach ($keys as $k) {
            if ((int) $k['page'] === $page) {
                $out[(int) $k['position']] = $k;
            }
        }
        ksort($out);
        return $out;
    }

    /** "+3", "-5", "+5:30" */
    protected static function offsetHours(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '+';
        $abs = abs($minutes);
        $h = intdiv($abs, 60);
        $m = $abs % 60;
        return $sign . $h . ($m ? ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
    }
}
