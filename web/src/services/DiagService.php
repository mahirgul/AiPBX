<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../priv_helper.php';

/**
 * Problem recording (System → Health checks): the admin starts it, repeats the
 * problem and stops it; conf/sbin/aipbx-diag (root, through aipbx-priv) packs the
 * Asterisk log with its SIP messages, the call data of that time and the PBX
 * state into one masked zip to download and attach to a report.
 */
final class DiagService
{
    public const DIR = '/var/lib/aipbx/diag';
    private const NAME = '/^aipbx-diag-\d{8}-\d{6}\.zip$/';

    /** @return array{running: bool, start: ?int, minutes: ?int, options: ?array<string, bool>, zips: list<array{name: string, size: int, mtime: int}>} */
    public static function status(): array
    {
        $res = PrivHelper::run(['diag', 'status']);
        $data = $res['success'] ? json_decode($res['output'], true) : null;
        return is_array($data) ? $data + ['running' => false, 'start' => null, 'minutes' => null, 'options' => null, 'zips' => []]
            : ['running' => false, 'start' => null, 'minutes' => null, 'options' => null, 'zips' => []];
    }

    public const OPT_KEYS = [
        'asterisk_log', 'sip_pcap', 'rtp_pcap', 'calls', 'queue_log',
        'state', 'selftest', 'errors', 'sys_logs', 'config',
    ];

    /** @return array<string, bool> */
    public static function defaultOptions(): array
    {
        $out = [];
        foreach (self::OPT_KEYS as $k) {
            $out[$k] = !in_array($k, ['rtp_pcap', 'sys_logs'], true);
        }
        return $out;
    }

    public static function start(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $minutes = max(1, min(60, (int) ($data['minutes'] ?? 15)));
            $args = ['start', 'minutes=' . $minutes];
            foreach (self::OPT_KEYS as $k) {
                $val = !empty($data['opt_' . $k]) ? '1' : '0';
                $args[] = $k . '=' . $val;
            }
            self::helper($args);
            writeAuditLog('system', 'diag', 'start', "Problem recording started ({$minutes} min at most)", 'create', $_SESSION['user_id'] ?? null);
            return sprintf(t('diag.started'), $minutes);
        });
    }

    public static function stop(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () {
            $out = json_decode(self::helper(['stop']), true);
            $zip = (string) ($out['zip'] ?? '');
            writeAuditLog('system', 'diag', 'stop', 'Problem recording stopped' . ($zip !== '' ? ": {$zip}" : ''), 'update', $_SESSION['user_id'] ?? null);
            return $zip !== '' ? sprintf(t('diag.stopped'), $zip) : t('diag.not_running');
        });
    }

    public static function delete(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $name = self::validName((string) ($data['name'] ?? ''));
            self::helper(['delete', 'name=' . $name]);
            writeAuditLog('system', 'diag', $name, "Problem recording deleted: {$name}", 'delete', $_SESSION['user_id'] ?? null);
            return t('diag.deleted');
        });
    }

    /** The zip's path for the download (it is in the audit log). */
    public static function downloadPath(string $name): string
    {
        $name = self::validName($name);
        $path = self::DIR . '/' . $name;
        if (!is_file($path) || !is_readable($path)) {
            throw new \Exception(t('diag.err_missing'));
        }
        writeAuditLog('system', 'diag', $name, "Problem recording downloaded: {$name}", 'download', $_SESSION['user_id'] ?? null);
        return $path;
    }

    private static function validName(string $name): string
    {
        if (!preg_match(self::NAME, $name)) {
            throw new \Exception(t('diag.err_missing'));
        }
        return $name;
    }

    private static function helper(array $args): string
    {
        $res = PrivHelper::run(array_merge(['diag'], $args));
        if (!$res['success']) {
            throw new \Exception($res['output'] !== '' ? preg_replace('/^aipbx-priv: /', '', $res['output']) : t('diag.err_helper'));
        }
        return $res['output'];
    }
}
