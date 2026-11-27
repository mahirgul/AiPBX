<?php
require_once __DIR__ . '/QrLoginService.php';

/**
 * A mobile sign-in for an extension, asked for by another system through the REST API (a billing or provisioning
 * system that has just created the SIP account). It hands out the same one-time link and QR as the invitation
 * e-mail, never a password, and only for plain extension users.
 *
 * It sits above QrLoginService: it chooses who may get a link, QrLoginService makes and later redeems it.
 */
class MobilePairingService
{
    /** Roles a link may be issued for. An administrator or manager signs in on the web, so a key must not mint their session. */
    private const ROLES = ['user', 'cc_agent'];

    /**
     * @param string $idOrExtension sys_users id or the extension number
     * @return array{success: bool, status: int, error?: string, data?: array<string, mixed>}
     */
    public static function issue(string $idOrExtension): array
    {
        $db = getDB();
        $idOrExtension = trim($idOrExtension);
        $user = false;
        if ($idOrExtension !== '') {
            $stmt = $db->prepare("SELECT id, username, role, extension, two_factor_enabled FROM sys_users
                                  WHERE extension = ? AND extension != '' LIMIT 1");
            $stmt->execute([$idOrExtension]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$user && ctype_digit($idOrExtension) && (int) $idOrExtension > 0) {
            $stmt = $db->prepare("SELECT id, username, role, extension, two_factor_enabled FROM sys_users
                                  WHERE id = ? AND extension IS NOT NULL AND extension != '' LIMIT 1");
            $stmt->execute([(int) $idOrExtension]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$user) {
            return ['success' => false, 'status' => 404, 'error' => t('srv_qr.err_pairing_none')];
        }
        if (!in_array((string) $user['role'], self::ROLES, true)) {
            return ['success' => false, 'status' => 403, 'error' => t('srv_qr.err_pairing_role')];
        }
        if (!QrLoginService::emailLinkAllowed($user)) {
            return ['success' => false, 'status' => 403, 'error' => t('srv_qr.err_email_link_blocked')];
        }

        $link = QrLoginService::createEmailLink((int) $user['id']);
        if (!$link['success']) {
            return ['success' => false, 'status' => 422, 'error' => (string) $link['error']];
        }
        parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $query);
        $inspect = QrLoginService::inspectToken((string) ($query['token'] ?? ''));
        writeAuditLog(null, 'user_account', $user['id'], "Mobile sign-in link issued through the API: {$user['username']}", 'create');

        return ['success' => true, 'status' => 200, 'data' => [
            'extension' => $user['extension'],
            'username' => $user['username'],
            'url' => $link['url'],
            'qr_data_uri' => QrLoginService::renderQr($inspect['payload'] ?? []),
            'server_url' => $inspect['server_url'] ?? '',
            'expires_at' => $link['expires_at'],
        ]];
    }
}
