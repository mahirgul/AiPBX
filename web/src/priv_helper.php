<?php
/**
 * Single entry point for operations that need root.
 *
 * The portal never calls a system tool (systemctl, postconf, firewall-cmd,
 * fail2ban-client) through sudo directly; everything goes through
 * `aipbx-priv`, which install.sh installs into /usr/local/sbin and which
 * validates its arguments against a whitelist (source: conf/sbin/aipbx-priv).
 * sudoers opens only this script to www-data.
 */

class PrivHelper {
    const BIN = '/usr/local/sbin/aipbx-priv';

    /**
     * @param string[] $args aipbx-priv subcommand and its arguments (e.g. ['fw', 'list-all'])
     * @param ?string $input what the command reads from stdin: a secret goes this way, as a
     *                       command line can be read by every local user
     * @return array{success: bool, output: string}
     */
    public static function run(array $args, ?string $input = null): array {
        $cmd = 'sudo -n ' . self::BIN . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        if ($input !== null) {
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) {
                return ['success' => false, 'output' => ''];
            }
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            return ['success' => proc_close($proc) === 0, 'output' => rtrim($output, "\n")];
        }
        exec($cmd, $out, $ret);
        return ['success' => $ret === 0, 'output' => implode("\n", $out)];
    }
}
