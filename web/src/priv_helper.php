<?php
/**
 * Root yetkisi gerektiren işlemler için tek giriş noktası.
 *
 * Portal hiçbir sistem aracını (systemctl, postconf, firewall-cmd,
 * fail2ban-client) doğrudan sudo ile çağırmaz; hepsi install.sh'in
 * /usr/local/sbin'e kurduğu, argümanları beyaz listeyle doğrulayan
 * `aipbx-priv` üzerinden geçer (kaynak: conf/sbin/aipbx-priv). sudoers
 * www-data'ya yalnızca bu script'i açar.
 */

class PrivHelper {
    const BIN = '/usr/local/sbin/aipbx-priv';

    /**
     * @param string[] $args aipbx-priv alt komutu ve argümanları (ör. ['fw', 'list-all'])
     * @return array{success: bool, output: string}
     */
    public static function run(array $args): array {
        $cmd = 'sudo -n ' . self::BIN . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $ret);
        return ['success' => $ret === 0, 'output' => implode("\n", $out)];
    }
}
