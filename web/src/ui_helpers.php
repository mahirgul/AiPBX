<?php
/**
 * Merkezi UI Bileşen Yardımcıları (buton / form / rozet / tablo satırı üreticileri)
 *
 * 2026-08-31'de config.php'den (483 satır) ayrıldı: config.php ortam değişkenleri,
 * DB bağlantısı, ayar okuma ve oturum/güvenlik yardımcılarını barındırmalı; HTML
 * üreten UI fonksiyonlarının orada işi yoktu. Geriye dönük uyum için config.php
 * bu dosyayı sonunda require ediyor — mevcut ~30 tüketici dosyada değişiklik
 * gerekmiyor.
 */
/**
 * UI Component Helpers for Centralized Single-Point UI Management
 */
function uiSaveButton($title = 'Kaydet', $extra_attr = '', $icon = 'fa-save') {
    return '<button type="submit" class="btn btn-primary" title="' . htmlspecialchars($title, ENT_QUOTES) . '" ' . $extra_attr . '><i class="fas ' . htmlspecialchars($icon, ENT_QUOTES) . '"></i><span class="btn-label">' . htmlspecialchars($title, ENT_QUOTES) . '</span></button>';
}

function uiCancelButton($onclick = '', $title = 'İptal') {
    $click_attr = !empty($onclick) ? 'onclick="' . htmlspecialchars($onclick, ENT_QUOTES) . '"' : '';
    return '<button type="button" class="btn btn-secondary" ' . $click_attr . ' title="' . htmlspecialchars($title, ENT_QUOTES) . '"><i class="fas fa-times"></i><span class="btn-label">' . htmlspecialchars($title, ENT_QUOTES) . '</span></button>';
}

function uiModalFooter($close_fn = '', $save_title = 'Kaydet', $save_attr = '', $save_icon = 'fa-save') {
    return '<div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">' .
               uiCancelButton($close_fn, 'İptal') .
               uiSaveButton($save_title, $save_attr, $save_icon) .
           '</div>';
}

/**
 * Renders the 1-click Aktif/Pasif status-toggle form that ends nearly every list row.
 * Posts to each page's existing 'toggle_status' handler (PBXHelper::toggleStatus()) — $idFieldName
 * is passed in rather than assumed, so no page's POST handler needs to change to adopt this.
 */
function uiStatusToggleForm($id, $isActive, $idFieldName, $toggleField = 'toggle_status') {
    $active = ((int)$isActive === 1);
    $btnClass = $active ? 'btn-success' : 'btn-danger';
    $icon = $active ? 'fa-check-circle' : 'fa-times-circle';
    $label = $active ? 'Aktif' : 'Pasif';
    return '<form method="POST" autocomplete="off" style="display:inline;">'
        . '<input type="hidden" name="csrf_token" value="' . getCSRFToken() . '">'
        . '<input type="hidden" name="' . htmlspecialchars($toggleField) . '" value="1">'
        . '<input type="hidden" name="' . htmlspecialchars($idFieldName) . '" value="' . htmlspecialchars($id) . '">'
        . '<button type="submit" class="btn btn-sm ' . $btnClass . '" title="Durumu Değiştir (Tıkla)" style="padding: 2px 8px; font-size: 11px;">'
        . '<i class="fas ' . $icon . '"></i> ' . $label
        . '</button></form>';
}

/**
 * Renders the delete-confirm mini-form (trash icon + JS confirm()) that ends nearly every list row.
 * $extraHidden lets pages with a compound delete key (e.g. roles.php's role_key) add extra hidden
 * fields after the id field, in insertion order, without needing their own copy of this form.
 */
function uiDeleteForm($id, $idFieldName, $deleteField, $confirmMessage = 'Bu kaydı silmek istediğinize emin misiniz?', $title = 'Sil', $icon = 'fa-trash-alt', array $extraHidden = [], $formStyle = 'display:inline;') {
    $extraHtml = '';
    foreach ($extraHidden as $name => $val) {
        $extraHtml .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($val) . '">';
    }
    return '<form method="POST" autocomplete="off" style="' . htmlspecialchars($formStyle) . '" onsubmit="return confirm(\'' . htmlspecialchars($confirmMessage, ENT_QUOTES) . '\');">'
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
function uiEditButton(array $row, $editJsFn, $title = 'Düzenle', $icon = 'fa-edit', $btnClass = 'btn-secondary') {
    $rowJson = json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<button class="btn ' . htmlspecialchars($btnClass) . ' btn-sm" onclick=\'' . $editJsFn . '(' . $rowJson . ')\' title="' . htmlspecialchars($title, ENT_QUOTES) . '"><i class="fas ' . htmlspecialchars($icon) . '"></i></button>';
}

/**
 * Composes uiEditButton() + uiDeleteForm() into the standard .table-actions-cell wrapper — the
 * common case where a row's actions are exactly "edit" + "delete". Set $showDelete = false for rows
 * where deletion is conditionally disabled (e.g. end_call.php's built-in hangup/busy/congestion).
 */
function uiRowActions(array $row, $editJsFn, $idFieldName, $deleteField, $confirmMessage = 'Bu kaydı silmek istediğinize emin misiniz?', $deleteTitle = 'Sil', $deleteIcon = 'fa-trash-alt', $editTitle = 'Düzenle', $editIcon = 'fa-edit', array $extraHidden = [], $showDelete = true, $editBtnClass = 'btn-secondary') {
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
 * Centralized Empty Table State Row Renderer
 */
function uiTableEmptyRow($colspan = 1, $message = 'Henüz kayıt bulunamadı', $icon = 'fa-inbox') {
    return '<tr><td colspan="' . (int)$colspan . '">' .
               '<div class="empty-table-box">' .
                   '<i class="fas ' . htmlspecialchars($icon) . '"></i>' .
                   '<span>' . htmlspecialchars($message) . '</span>' .
               '</div>' .
           '</td></tr>';
}

/**
 * Desteklenen telefon modları:
 * - web: Web softphone (tarayıcı üzerinden WebRTC arama)
 * - mobil: Mobil softphone / Push bildirimli mobil WebRTC
 * - sip: Masaüstü / donanım IP SIP telefonu
 * - video: Görüntülü arama yeteneği (VP8/H.264)
 */
const SUPPORTED_PHONE_MODES = ['web', 'mobil', 'sip', 'video'];

/**
 * Ham mod metnini veya eski değerleri diziye dönüştürür.
 * Geriye dönük uyumluluk:
 * - 'both' veya boş/null -> ['web', 'mobil', 'sip', 'video']
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
 * Mod dizisini veritabanında saklanacak virgülle ayrılmış metne dönüştürür.
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


