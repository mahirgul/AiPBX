<?php
/**
 * The welcome card on a user's start page: first-step shortcuts to the pages this user
 * can open (several roles, several groups), until the user closes it.
 */
class WelcomeService
{
    /** On the user's own start page, until they close the card. */
    public static function shouldShow(): bool
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            return false;
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($path !== roleHomePath((string) ($_SESSION['user_role'] ?? ''))) {
            return false;
        }
        try {
            $st = getDB()->prepare('SELECT welcome_dismissed FROM sys_users WHERE id = ?');
            $st->execute([$id]);
            return (string) $st->fetchColumn() === '0';
        } catch (\PDOException $e) {
            return false;   // before the migration
        }
    }

    public static function dismiss(int $userId): void
    {
        getDB()->prepare('UPDATE sys_users SET welcome_dismissed = 1 WHERE id = ?')->execute([$userId]);
    }

    /**
     * Groups of shortcuts, each only with the pages this user may open.
     * @return list<array{title: string, links: list<array{href?: string, onclick?: string, icon: string, label: string}>}>
     */
    public static function groups(): array
    {
        $can = fn(string $module) => hasModulePermission($module, 'access') || hasModulePermission($module, 'view');
        $link = fn(string $href, string $icon, string $key) => ['href' => $href, 'icon' => $icon, 'label' => t($key)];
        $groups = [];

        $setup = array_values(array_filter([
            $can('certificates') ? $link('/certificates', 'fa-globe', 'welcome.domain') : null,
            $can('extensions') ? $link('/extensions', 'fa-users', 'welcome.extensions') : null,
            $can('trunks') ? $link('/trunks', 'fa-network-wired', 'welcome.trunk') : null,
            $can('time_conditions') ? $link('/time-conditions', 'fa-clock', 'welcome.hours') : null,
        ]));
        if ($setup !== []) {
            $groups[] = ['title' => t('welcome.group_setup'), 'links' => $setup];
        }

        $hasExtension = false;
        try {
            $st = getDB()->prepare("SELECT extension FROM sys_users WHERE id = ? AND extension IS NOT NULL AND extension <> '' AND COALESCE(extension_type, 'sip') = 'sip'");
            $st->execute([(int) ($_SESSION['user_id'] ?? 0)]);
            $hasExtension = (bool) $st->fetchColumn();
        } catch (\PDOException $e) {
        }
        $phone = [];
        if ($hasExtension) {
            $phone[] = ['onclick' => 'openQrLoginModal()', 'icon' => 'fa-qrcode', 'label' => t('welcome.mobile')];
            if ($can('my_phone')) {
                $phone[] = $link('/my-phone', 'fa-phone', 'welcome.my_phone');
            }
        }
        if ($can('chat')) {
            $phone[] = $link('/chat', 'fa-comments', 'welcome.chat');
        }
        if ($phone !== []) {
            $groups[] = ['title' => t('welcome.group_phone'), 'links' => $phone];
        }

        $fax = array_values(array_filter([
            $can('fax_send') ? $link('/fax-send', 'fa-paper-plane', 'welcome.fax_send') : null,
            $can('fax_inbox') ? $link('/fax-inbox', 'fa-inbox', 'welcome.fax_inbox') : null,
        ]));
        if ($fax !== []) {
            $groups[] = ['title' => t('welcome.group_fax'), 'links' => $fax];
        }

        if ($can('cc_agent') || userHasRole('cc_agent')) {
            $groups[] = ['title' => t('welcome.group_agent'), 'links' => [$link('/cc-agent', 'fa-headset', 'welcome.agent')]];
        }
        return $groups;
    }
}
