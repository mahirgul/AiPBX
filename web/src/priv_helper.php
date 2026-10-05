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
     * @return array{success: bool, output: string}
     */
    public static function run(array $args): array {
        $cmd = 'sudo -n ' . self::BIN . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $ret);
        return ['success' => $ret === 0, 'output' => implode("\n", $out)];
    }
}
