<?php
/**
 * Central UI component helpers (button / form / badge / table row builders)
 *
 * Split out of config.php (483 lines) on 2026-08-31: config.php should hold
 * the environment variables, DB connection, settings reads and session/
 * security helpers; HTML-producing UI functions had no business there. For
 * backward compatibility config.php requires this file at its end — the ~30
 * existing consumer files need no change.
 */
/**
 * UI Component Helpers for Centralized Single-Point UI Management
 */
/**
 * The shared styles, in cascade order: a later file may refine an earlier one, so keep this order.
 */
function uiStylesheets(): string {
    $html = '';
    foreach (['cards', 'tables', 'forms', 'buttons', 'feedback', 'auth', 'topbar', 'widgets', 'responsive', 'helpers', 'utilities'] as $name) {
        $html .= '<link rel="stylesheet" href="' . asset('/assets/css/ui/' . $name . '.css') . '">' . "\n";
    }
    return $html;
}

/**
 * The title of a page, the same everywhere: bar, icon tile, title, then status badges on the left and
 * actions on the right, with the help button first. See /ui-kit.
 *
 *     echo uiPageHeader('fa-phone', $title, 'xHelpBox');
 *
 * With badges or actions:
 *
 *     echo uiPageHeaderOpen('fa-phone', $title, 'xHelpBox');
 *     ...badges...
 *     echo uiPageHeaderActions();
 *     ...buttons...
 *     echo uiPageHeaderClose();
 *
 * @param string $icon  'fa-x', or 'fab fa-x' for a brand icon
 * @param string $title Ready HTML, like every t() on these pages
 * @param string $help  Id of the uiHelpBoxOpen() it opens
 */
function uiPageHeaderOpen(string $icon, string $title, string $help = ''): string {
    $GLOBALS['uiPageHeaderHelp'] = $help;
    $class = str_contains($icon, ' ') ? $icon : 'fas ' . $icon;
    return '<div class="card-header"><div class="card-title"><i class="' . htmlspecialchars($class) . '"></i> ' . $title;
}

function uiPageHeaderActions(): string {
    $help = (string) ($GLOBALS['uiPageHeaderHelp'] ?? '');
    return '</div><div class="page-actions">' . ($help !== '' ? uiHelpButton($help) : '');
}

function uiPageHeaderClose(): string {
    return '</div></div>';
}

function uiPageHeader(string $icon, string $title, string $help = ''): string {
    return uiPageHeaderOpen($icon, $title, $help) . uiPageHeaderActions() . uiPageHeaderClose();
}

/** The round "?" that opens a help box. */
function uiHelpButton(string $boxId): string {
    return '<button type="button" class="btn-help" onclick="toggleModuleHelp(\'' . htmlspecialchars($boxId, ENT_QUOTES) . '\')" title="'
        . htmlspecialchars(t('common.module_guide'), ENT_QUOTES) . '"><i class="fas fa-question-circle"></i></button>';
}

/** A help box, closed until its button is pressed. The body goes between Open and Close. */
function uiHelpBoxOpen(string $id, string $title): string {
    return '<div class="module-help-box" id="' . htmlspecialchars($id, ENT_QUOTES) . '"><h4><i class="fas fa-info-circle"></i> ' . $title . '</h4>';
}

function uiHelpBoxClose(): string {
    return '</div>';
}

/**
 * A row of tabs, the same on every page. Panes are elements with data-tab-pane="key"; the shared
 * script shows the chosen one. An item with 'onclick' or 'href' does its own thing instead.
 *
 * @param array<string, array{label: string, icon?: string, badge?: string, badge_html?: string, badge_class?: string, onclick?: string, href?: string}> $items
 */
function uiTabs(array $items, string $active): string {
    $html = '<div class="ui-tabs" role="tablist">';
    foreach ($items as $key => $item) {
        $on = (string) $key === $active;
        $icon = !empty($item['icon']) ? '<i class="fas ' . htmlspecialchars($item['icon'], ENT_QUOTES) . '"></i> ' : '';
        // 'badge' is text; 'badge_html' is trusted markup (an icon), never user data.
        $badgeInner = ($item['badge_html'] ?? '') !== '' ? $item['badge_html'] : htmlspecialchars($item['badge'] ?? '');
        $badge = $badgeInner !== ''
            ? ' <span class="badge ' . htmlspecialchars($item['badge_class'] ?? 'badge-secondary', ENT_QUOTES) . '">' . $badgeInner . '</span>'
            : '';
        $inner = $icon . '<span>' . htmlspecialchars($item['label']) . '</span>' . $badge;
        $class = 'ui-tab' . ($on ? ' active' : '');
        $data = ' data-tab="' . htmlspecialchars((string) $key, ENT_QUOTES) . '" role="tab" aria-selected="' . ($on ? 'true' : 'false') . '"';
        if (!empty($item['href'])) {
            $html .= '<a href="' . htmlspecialchars($item['href'], ENT_QUOTES) . '" class="' . $class . '"' . $data . ' style="text-decoration: none;">' . $inner . '</a>';
        } else {
            $click = $item['onclick'] ?? 'UIHelper.selectTab(this)';
            $html .= '<button type="button" class="' . $class . '"' . $data . ' onclick="' . htmlspecialchars($click, ENT_QUOTES) . '">' . $inner . '</button>';
        }
    }
    return $html . '</div>';
}

function uiSaveButton($title = null, $extra_attr = '', $icon = 'fa-save') {
    $title = $title ?? t('common.save');
    $module = getModuleKeyForPage();
    if (!hasModulePermission($module, 'edit')) {
        return '';
    }
    return '<button type="submit" class="btn btn-primary" title="' . htmlspecialchars($title, ENT_QUOTES) . '" ' . $extra_attr . '><i class="fas ' . htmlspecialchars($icon, ENT_QUOTES) . '"></i><span class="btn-label">' . htmlspecialchars($title, ENT_QUOTES) . '</span></button>';
}

function uiCancelButton($onclick = '', $title = null) {
    $title = $title ?? t('common.cancel');
    $click_attr = !empty($onclick) ? 'onclick="' . htmlspecialchars($onclick, ENT_QUOTES) . '"' : '';
    return '<button type="button" class="btn btn-secondary" ' . $click_attr . ' title="' . htmlspecialchars($title, ENT_QUOTES) . '"><i class="fas fa-times"></i><span class="btn-label">' . htmlspecialchars($title, ENT_QUOTES) . '</span></button>';
}

function uiModalFooter($close_fn = '', $save_title = null, $save_attr = '', $save_icon = 'fa-save') {
    $save_title = $save_title ?? t('common.save');
    $module = getModuleKeyForPage();
    $can_edit = hasModulePermission($module, 'edit');
    $close_label = $can_edit ? t('common.cancel') : t('common.close');
    return '<div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">' .
               uiCancelButton($close_fn, $close_label) .
               ($can_edit ? uiSaveButton($save_title, $save_attr, $save_icon) : '') .
           '</div>';
}

/**
 * Renders the 1-click Aktif/Pasif status-toggle form that ends nearly every list row.
 * Posts to each page's existing 'toggle_status' handler (PBXHelper::toggleStatus()) — $idFieldName
 * is passed in rather than assumed, so no page's POST handler needs to change to adopt this.
 */
function uiStatusToggleForm($id, $isActive, $idFieldName, $toggleField = 'toggle_status') {
    $active = ((int)$isActive === 1);
    $icon = $active ? 'fa-check-circle' : 'fa-times-circle';
    $label = $active ? t('common.active') : t('common.passive');
    $module = getModuleKeyForPage();
    if (!hasModulePermission($module, 'edit')) {
        $badgeClass = $active ? 'badge-success' : 'badge-danger';
        return '<span class="badge ' . $badgeClass . '" style="padding: 3px 8px; font-size: 11px;">'
            . '<i class="fas ' . $icon . '"></i> ' . $label
            . '</span>';
    }
    $btnClass = $active ? 'btn-success' : 'btn-danger';
    return '<form method="POST" autocomplete="off" style="display:inline;">'
        . '<input type="hidden" name="csrf_token" value="' . getCSRFToken() . '">'
        . '<input type="hidden" name="' . htmlspecialchars($toggleField) . '" value="1">'
        . '<input type="hidden" name="' . htmlspecialchars($idFieldName) . '" value="' . htmlspecialchars($id) . '">'
        . '<button type="submit" class="btn btn-sm ' . $btnClass . '" title="' . htmlspecialchars(t('common.toggle_status'), ENT_QUOTES) . '" style="padding: 2px 8px; font-size: 11px;">'
        . '<i class="fas ' . $icon . '"></i> ' . $label
        . '</button></form>';
}

/**
 * Renders the delete-confirm mini-form (trash icon + JS confirm()) that ends nearly every list row.
 * $extraHidden lets pages with a compound delete key (e.g. roles.php's role_key) add extra hidden
 * fields after the id field, in insertion order, without needing their own copy of this form.
 */
function uiDeleteForm($id, $idFieldName, $deleteField, $confirmMessage = null, $title = null, $icon = 'fa-trash-alt', array $extraHidden = [], $formStyle = 'display:inline;') {
    $confirmMessage = $confirmMessage ?? t('common.confirm_delete');
    $title = $title ?? t('common.delete');
    $module = getModuleKeyForPage();
    if (!hasModulePermission($module, 'delete')) {
        return '';
    }
    $extraHtml = '';
    foreach ($extraHidden as $name => $val) {
        $extraHtml .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($val) . '">';
    }
    return '<form method="POST" autocomplete="off" style="' . htmlspecialchars($formStyle) . '" onsubmit="return confirm(' . jsArg($confirmMessage) . ');">'
        . '<input type="hidden" name="csrf_token" value="' . getCSRFToken() . '">'
        . '<input type="hidden" name="' . htmlspecialchars($deleteField) . '" value="1">'
        . '<input type="hidden" name="' . htmlspecialchars($idFieldName) . '" value="' . htmlspecialchars($id) . '">'
        . $extraHtml
        . '<button type="submit" class="btn btn-danger btn-sm" title="' . htmlspecialchars($title, ENT_QUOTES) . '"><i class="fas ' . htmlspecialchars($icon) . '"></i></button>'
        . '</form>';
}

/**
 * Renders just the edit button (calls the page's existing openEditXModal(rowJson) JS function — left
 * page-specific, not touched here). Standalone so pages whose actions cell has extra custom buttons
 * (e.g. system_users.php's "reset password") can still reuse this piece instead of the full
 * uiRowActions() wrapper.
 */
function uiEditButton(array $row, $editJsFn, $title = null, $icon = 'fa-edit', $btnClass = 'btn-secondary') {
    $title = $title ?? t('common.edit');
    $module = getModuleKeyForPage();
    $can_edit = hasModulePermission($module, 'edit');
    if (!$can_edit) {
        $title = t('common.view');
        $icon = 'fa-eye';
    }
    $safeFn = preg_replace('/[^a-zA-Z0-9_$.]/', '', (string) $editJsFn);
    return '<button class="btn ' . htmlspecialchars($btnClass) . ' btn-sm" onclick=\'' . $safeFn . '(' . jsArg($row) . ')\' title="' . htmlspecialchars($title, ENT_QUOTES) . '"><i class="fas ' . htmlspecialchars($icon) . '"></i></button>';
}

/**
 * Composes uiEditButton() + uiDeleteForm() into the standard .table-actions-cell wrapper — the
 * common case where a row's actions are exactly "edit" + "delete". Set $showDelete = false for rows
 * where deletion is conditionally disabled (e.g. end_call.php's built-in hangup/busy/congestion).
 */
function uiRowActions(array $row, $editJsFn, $idFieldName, $deleteField, $confirmMessage = null, $deleteTitle = null, $deleteIcon = 'fa-trash-alt', $editTitle = null, $editIcon = 'fa-edit', array $extraHidden = [], $showDelete = true, $editBtnClass = 'btn-secondary') {
    $module = getModuleKeyForPage();
    if (!hasModulePermission($module, 'delete')) {
        $showDelete = false;
    }
    $id = $row['id'] ?? '';
    $html = '<div class="table-actions-cell">' . uiEditButton($row, $editJsFn, $editTitle, $editIcon, $editBtnClass);
    if ($showDelete) {
        $html .= uiDeleteForm($id, $idFieldName, $deleteField, $confirmMessage, $deleteTitle, $deleteIcon, $extraHidden);
    }
    return $html . '</div>';
}

/**
 * Map-driven status/event badge (e.g. $map = ['SUCCESS' => 'success', 'FAILED' => 'danger']).
 * Falls back to $default when $value isn't in $map, mirroring DestinationRegistry::badgeClassFor()'s
 * ?? fallback style. $label defaults to $value itself when not given. PHP-rendered pages only —
 * client-side (JS-built) badges such as cc_supervisor.php's live view are out of scope.
 */
function uiStatusBadge($value, array $map, $default = 'info', $label = null, $icon = '') {
    $cls = 'badge-' . ($map[$value] ?? $default);
    $text = $label ?? $value;
    $iconHtml = $icon ? '<i class="fas ' . htmlspecialchars($icon) . '"></i> ' : '';
    return '<span class="badge ' . $cls . '">' . $iconHtml . htmlspecialchars($text) . '</span>';
}

/**
 * A table, the same on every page: scroll frame, table, header row. The page writes only its rows:
 *
 *     echo uiTableOpen([$name, ['label' => $ext, 'class' => 'col-hide-mobile'], '']);
 *     foreach ($rows as $r) { ... }   // <tr>...</tr>
 *     echo uiTableClose();
 *
 * A column is its label (ready HTML, like t()) or ['label', 'class', 'title']; null skips it (a column
 * only some users see: $canEdit ? $actions : null). No columns, no header row.
 * $opt: id, body_id, search (false: no search, paging or sorting), class (a script's hook),
 * attrs (trusted data-* for a script), frame (classes on the frame, e.g. a height limit).
 */
function uiTableOpen(array $columns, array $opt = []): string {
    $th = '';
    foreach ($columns as $c) {
        if ($c === null) {
            continue;
        }
        $c = is_array($c) ? $c : ['label' => $c];
        $th .= '<th' . (!empty($c['class']) ? ' class="' . htmlspecialchars($c['class'], ENT_QUOTES) . '"' : '')
            . (!empty($c['title']) ? ' title="' . htmlspecialchars($c['title'], ENT_QUOTES) . '"' : '') . '>' . ($c['label'] ?? '') . '</th>';
    }
    $class = trim('data-table ' . (($opt['search'] ?? true) ? '' : 'no-dt ') . ($opt['class'] ?? ''));
    $id = static fn(string $k): string => !empty($opt[$k]) ? ' id="' . htmlspecialchars($opt[$k], ENT_QUOTES) . '"' : '';
    return '<div class="' . trim('table-responsive ' . ($opt['frame'] ?? '')) . '"><table class="' . htmlspecialchars($class, ENT_QUOTES) . '"'
        . $id('id') . (!empty($opt['attrs']) ? ' ' . $opt['attrs'] : '') . '>' . ($th !== '' ? '<thead><tr>' . $th . '</tr></thead>' : '')
        . '<tbody' . $id('body_id') . '>';
}

function uiTableClose(): string {
    return '</tbody></table></div>';
}

/**
 * Centralized Empty Table State Row Renderer
 */
function uiTableEmptyRow($colspan = 1, $message = null, $icon = 'fa-inbox') {
    $message = $message ?? t('common.no_records');
    return '<tr><td colspan="' . (int)$colspan . '">' .
               '<div class="empty-table-box">' .
                   '<i class="fas ' . htmlspecialchars($icon) . '"></i>' .
                   '<span>' . htmlspecialchars($message) . '</span>' .
               '</div>' .
           '</td></tr>';
}

/**
 * Supported phone modes:
 * - web: web softphone (WebRTC calls in the browser)
 * - mobil: mobile softphone / mobile WebRTC with push notifications
 * - sip: desk / hardware IP SIP phone
 * - video: video call capability (VP8/H.264)
 */
const SUPPORTED_PHONE_MODES = ['web', 'mobil', 'sip', 'video'];

/**
 * Turns the raw mode text or legacy values into an array.
 * Backward compatibility:
 * - 'both' or empty/null -> ['web', 'mobil', 'sip', 'video']
 * - 'webrtc_only' -> ['web', 'mobil', 'video']
 * - 'sip_only' -> ['sip']
 * - 'web,mobil,sip,video' -> ['web', 'mobil', 'sip', 'video']
 *
 * @param string|null $raw
 * @return array<string>
 */
function parsePhoneModes(?string $raw): array {
    if ($raw === null || trim($raw) === '' || $raw === 'both') {
        return SUPPORTED_PHONE_MODES;
    }
    $trimmed = trim($raw);
    if ($trimmed === 'webrtc_only') {
        return ['web', 'mobil', 'video'];
    }
    if ($trimmed === 'sip_only') {
        return ['sip'];
    }
    $tokens = array_filter(array_map('trim', explode(',', $trimmed)));
    $filtered = [];
    foreach (SUPPORTED_PHONE_MODES as $m) {
        if (in_array($m, $tokens, true)) {
            $filtered[] = $m;
        }
    }
    return !empty($filtered) ? $filtered : SUPPORTED_PHONE_MODES;
}

/**
 * Turns the mode array into the comma-separated text stored in the database.
 *
 * @param array $modes
 * @return string
 */
function formatPhoneModes(array $modes): string {
    $filtered = [];
    foreach (SUPPORTED_PHONE_MODES as $m) {
        if (in_array($m, $modes, true)) {
            $filtered[] = $m;
        }
    }
    if (empty($filtered)) {
        return 'web';
    }
    return implode(',', $filtered);
}


