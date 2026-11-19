<?php

declare(strict_types=1);

/**
 * AGI scripts written in the portal (PBX -> AGI Scripts).
 *
 * Asterisk never runs them itself: the dialplan calls the aipbx-agi service
 * (bin/agi_server.php, FastAGI on 127.0.0.1:4573), which runs the script in the
 * scheduled-task sandbox as aipbx-task, with no network unless the script
 * asks for it. Scripts copied into agi-bin by hand are listed too; Asterisk
 * runs those directly, as before.
 */
final class AgiScriptService
{
    public const LANGUAGES = ['python', 'php', 'bash'];
    public const HOST = '127.0.0.1';
    public const PORT = 4573;
    public const AGI_BIN = '/var/lib/asterisk/agi-bin';
    /** Saved versions kept per script. */
    public const KEEP_VERSIONS = 20;
    private const NAME_RE = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return getDB()->query('SELECT id, name, description, language, network_access, timeout_sec, memory_mb, is_active, updated_at
                               FROM pbx_agi_scripts ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $st = getDB()->prepare('SELECT * FROM pbx_agi_scripts WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed>|null the active script of that name (for the service) */
    public static function findActiveByName(string $name): ?array
    {
        if (!preg_match(self::NAME_RE, $name)) {
            return null;
        }
        $st = getDB()->prepare('SELECT * FROM pbx_agi_scripts WHERE name = ? AND is_active = 1');
        $st->execute([$name]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Scripts placed in agi-bin by hand: plain files only, no hidden ones.
     *
     * @return list<string>
     */
    public static function agiBinScripts(string $dir = self::AGI_BIN): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (@scandir($dir) ?: [] as $f) {
            if ($f[0] !== '.' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/', $f) && is_file($dir . '/' . $f) && !is_link($dir . '/' . $f)) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * The choices of an AGI source: "script:<name>" runs through the sandbox,
     * "bin:<file>" is an agi-bin script.
     *
     * @return array<string, string> value => label
     */
    public static function choices(): array
    {
        $out = [];
        foreach (self::all() as $s) {
            if (!empty($s['is_active'])) {
                $out['script:' . $s['name']] = (string) $s['name'];
            }
        }
        foreach (self::agiBinScripts() as $f) {
            $out['bin:' . $f] = 'agi-bin/' . $f;
        }
        return $out;
    }

    /** The AGI() argument of a choice; '' when it is not one. */
    public static function dialplanTarget(string $choice): string
    {
        if (str_starts_with($choice, 'script:') && preg_match(self::NAME_RE, substr($choice, 7))) {
            return 'agi://' . self::HOST . ':' . self::PORT . '/' . substr($choice, 7);
        }
        if (str_starts_with($choice, 'bin:') && in_array(substr($choice, 4), self::agiBinScripts(), true)) {
            return substr($choice, 4);
        }
        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, errors: array<string, string>}
     */
    public static function save(array $data, ?int $userId = null): array
    {
        $id = (int) ($data['id'] ?? 0);
        $name = strtolower(trim((string) ($data['name'] ?? '')));
        $lang = (string) ($data['language'] ?? 'python');
        $content = str_replace("\r\n", "\n", (string) ($data['content'] ?? ''));
        $errors = [];
        if (!preg_match(self::NAME_RE, $name)) {
            $errors['name'] = t('agi_scripts.err_name');
        } else {
            $st = getDB()->prepare('SELECT id FROM pbx_agi_scripts WHERE name = ? AND id <> ?');
            $st->execute([$name, $id]);
            if ($st->fetchColumn()) {
                $errors['name'] = t('agi_scripts.err_name_taken');
            }
        }
        if (!in_array($lang, self::LANGUAGES, true)) {
            $errors['language'] = t('agi_scripts.err_language');
        }
        if (trim($content) === '' || strlen($content) > 262144 || str_contains($content, "\0")) {
            $errors['content'] = t('agi_scripts.err_content');
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        $row = [
            'name' => $name,
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255),
            'language' => $lang,
            'content' => $content,
            'network_access' => empty($data['network_access']) ? 0 : 1,
            'timeout_sec' => max(1, min(60, (int) ($data['timeout_sec'] ?? 10))),
            'memory_mb' => max(32, min(512, (int) ($data['memory_mb'] ?? 128))),
            'is_active' => array_key_exists('is_active', $data) && empty($data['is_active']) ? 0 : 1,
            'updated_by' => $userId,
        ];
        $db = getDB();
        $old = $id > 0 ? self::find($id) : null;
        if ($id > 0 && !$old) {
            return ['ok' => false, 'errors' => ['name' => t('agi_scripts.err_not_found')]];
        }
        $db->beginTransaction();
        try {
            if ($old) {
                $set = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($row)));
                $db->prepare("UPDATE pbx_agi_scripts SET $set WHERE id = ?")->execute([...array_values($row), $id]);
            } else {
                $db->prepare('INSERT INTO pbx_agi_scripts (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')
                    ->execute(array_values($row));
                $id = (int) $db->lastInsertId();
            }
            // A version per change of the code; the list stays short.
            if (!$old || $old['content'] !== $content || $old['language'] !== $lang) {
                $db->prepare('INSERT INTO pbx_agi_script_versions (script_id, language, content, created_by) VALUES (?, ?, ?, ?)')
                    ->execute([$id, $lang, $content, $userId]);
                $db->prepare('DELETE FROM pbx_agi_script_versions WHERE script_id = ? AND id NOT IN (
                                SELECT id FROM (SELECT id FROM pbx_agi_script_versions WHERE script_id = ? ORDER BY id DESC LIMIT ' . self::KEEP_VERSIONS . ') keep)')
                    ->execute([$id, $id]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        if (function_exists('writeAuditLog')) {
            writeAuditLog('pbx', 'agi_script', $id, $name, $old ? 'update' : 'create', $userId);
        }
        return ['ok' => true, 'id' => $id, 'errors' => []];
    }

    /** @return list<array<string, mixed>> newest first, without the code */
    public static function versions(int $scriptId): array
    {
        $st = getDB()->prepare('SELECT id, language, created_at, created_by, LENGTH(content) AS size FROM pbx_agi_script_versions WHERE script_id = ? ORDER BY id DESC');
        $st->execute([$scriptId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public static function version(int $scriptId, int $versionId): ?array
    {
        $st = getDB()->prepare('SELECT * FROM pbx_agi_script_versions WHERE script_id = ? AND id = ?');
        $st->execute([$scriptId, $versionId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Going back to a version is a new save of its code. */
    public static function restore(int $scriptId, int $versionId, ?int $userId = null): array
    {
        $s = self::find($scriptId);
        $v = self::version($scriptId, $versionId);
        if (!$s || !$v) {
            return ['ok' => false, 'errors' => ['name' => t('agi_scripts.err_not_found')]];
        }
        return self::save(['language' => $v['language'], 'content' => $v['content']] + $s, $userId);
    }

    public static function delete(int $id, ?int $userId = null): bool
    {
        $s = self::find($id);
        if (!$s) {
            return false;
        }
        getDB()->prepare('DELETE FROM pbx_agi_scripts WHERE id = ?')->execute([$id]);
        if (function_exists('writeAuditLog')) {
            writeAuditLog('pbx', 'agi_script', $id, (string) $s['name'], 'delete', $userId);
        }
        return true;
    }

    /** Where a script is used (Dynamic Routing steps); a used script is not deleted. @return list<string> */
    public static function usedBy(string $name): array
    {
        $st = getDB()->prepare('SELECT name FROM pbx_ivr_data_steps WHERE agi_script = ?');
        $st->execute(['script:' . $name]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** The starting code of a new script. */
    public static function template(string $lang): string
    {
        $file = dirname(__DIR__, 2) . '/templates/agi/example.' . match ($lang) {
            'php' => 'php',
            'bash' => 'sh',
            default => 'py',
        };
        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /**
     * Runs a saved script the way a call would: this acts as Asterisk on the
     * FastAGI connection and answers its commands. GET VARIABLE returns the
     * sample values, SET VARIABLE is recorded, anything else answers "200 result=0".
     *
     * @param array<string, string> $vars sample channel variables (and agi_arg_N)
     * @return array{ok: bool, set: array<string, string>, log: list<string>, ms: int, error: string}
     */
    public static function test(string $name, array $vars, string $host = self::HOST, int $port = self::PORT): array
    {
        $t0 = hrtime(true);
        $out = ['ok' => false, 'set' => [], 'log' => [], 'ms' => 0, 'error' => ''];
        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 2);
        if (!$sock) {
            $out['error'] = t('agi_scripts.err_service');
            return $out;
        }
        stream_set_timeout($sock, 65);
        $args = [];
        foreach ($vars as $k => $v) {
            if (preg_match('/^agi_arg_(\d+)$/', $k, $m)) {
                $args[(int) $m[1]] = $v;
            }
        }
        ksort($args);
        $env = [
            'agi_network' => 'yes', 'agi_network_script' => $name, 'agi_request' => "agi://{$host}/{$name}",
            'agi_channel' => 'Local/test-00000001;1', 'agi_language' => $vars['lang'] ?? 'en', 'agi_type' => 'Local',
            'agi_uniqueid' => '0000000000.1', 'agi_version' => 'aipbx-test', 'agi_callerid' => $vars['caller_id'] ?? '',
            'agi_calleridname' => $vars['caller_name'] ?? '', 'agi_dnid' => $vars['did'] ?? '', 'agi_context' => 'aipbx-test',
            'agi_extension' => $vars['exten'] ?? 's', 'agi_priority' => '1', 'agi_enhanced' => '0.0', 'agi_accountcode' => '',
            'agi_threadid' => '1',
        ];
        $block = '';
        foreach ($env as $k => $v) {
            $block .= $k . ': ' . self::oneLine($v) . "\n";
        }
        foreach ($args as $i => $v) {
            $block .= 'agi_arg_' . $i . ': ' . self::oneLine($v) . "\n";
        }
        fwrite($sock, $block . "\n");
        $commands = 0;
        while (($line = fgets($sock, 8192)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '' || ++$commands > 500) {
                continue;
            }
            $out['log'][] = mb_substr($line, 0, 300);
            [$reply, $hangup] = self::answer($line, $vars, $out['set']);
            fwrite($sock, $reply . "\n");
            if ($hangup) {
                break;
            }
        }
        $meta = stream_get_meta_data($sock);
        fclose($sock);
        $out['ms'] = (int) ((hrtime(true) - $t0) / 1e6);
        if (!empty($meta['timed_out'])) {
            $out['error'] = t('agi_scripts.err_timeout');
            return $out;
        }
        $out['ok'] = true;
        return $out;
    }

    /**
     * The test's answer to one AGI command.
     *
     * @param array<string, string> $vars
     * @param array<string, string> $set
     * @return array{0: string, 1: bool} reply, hang up
     */
    public static function answer(string $line, array $vars, array &$set): array
    {
        $cmd = strtoupper((string) strtok($line, ' '));
        $rest = trim(substr($line, strlen($cmd)));
        $sub = strtoupper((string) strtok($rest, ' '));
        if ($cmd === 'GET' && $sub === 'VARIABLE' || $cmd === 'GET' && $sub === 'FULL') {
            $name = trim(preg_replace('/^(VARIABLE|FULL\s+VARIABLE)\s+/i', '', $rest) ?? '', " \"");
            $name = preg_replace('/^\$\{(.*)\}$/', '$1', $name) ?? $name;
            $value = $set[$name] ?? $vars[$name] ?? null;
            return [$value === null ? '200 result=0' : '200 result=1 (' . self::oneLine($value) . ')', false];
        }
        if ($cmd === 'SET' && $sub === 'VARIABLE') {
            if (preg_match('/^VARIABLE\s+("?)([^\s"]+)\1\s+(.*)$/is', $rest, $m)) {
                $set[$m[2]] = mb_substr(trim($m[3], '"'), 0, 500);
            }
            return ['200 result=1', false];
        }
        if ($cmd === 'GET' && $sub === 'DATA') {
            return ['200 result=' . self::oneLine($vars['input'] ?? ''), false];
        }
        if ($cmd === 'HANGUP') {
            return ['200 result=1', true];
        }
        return ['200 result=0', false];
    }

    private static function oneLine(string $v): string
    {
        return str_replace(["\r", "\n", ')'], [' ', ' ', ']'], $v);
    }
}
