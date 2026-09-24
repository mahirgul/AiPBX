<?php
/**
 * AiPBX.bid — Central Data Loader & Multilingual Helper
 * Loads data.json and provides trilingual (TR/EN/DE) rendering helpers.
 */

$dataFile = __DIR__ . '/../data.json';
$data = [];
if (file_exists($dataFile)) {
    $data = json_decode(file_get_contents($dataFile), true) ?: [];
}

// ── Active Language Detection (TR / EN / DE) ─────────────────────────────────
// Priority 1: ?lang= query param
if (isset($_GET['lang']) && in_array(strtolower($_GET['lang']), ['tr', 'en', 'de'], true)) {
    $LANG = strtolower($_GET['lang']);
    setcookie('aipbx_lang', $LANG, time() + 365 * 24 * 3600, '/');
}
// Priority 2: Cookie
elseif (isset($_COOKIE['aipbx_lang']) && in_array(strtolower($_COOKIE['aipbx_lang']), ['tr', 'en', 'de'], true)) {
    $LANG = strtolower($_COOKIE['aipbx_lang']);
}
// Priority 3: Cloudflare Country Header
elseif (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
    $country = strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']);
    if (in_array($country, ['AT', 'DE', 'CH', 'LI'], true)) {
        $LANG = 'de';
    } elseif (in_array($country, ['TR', 'AZ'], true)) {
        $LANG = 'tr';
    } else {
        $LANG = 'en';
    }
}
// Priority 4: Browser Accept-Language
elseif (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $LANG = 'en';
    $accept = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    $langs = explode(',', $accept);
    foreach ($langs as $l) {
        $clean = strtolower(substr(trim($l), 0, 2));
        if ($clean === 'tr' || $clean === 'az') {
            $LANG = 'tr';
            break;
        } elseif ($clean === 'de') {
            $LANG = 'de';
            break;
        }
    }
} else {
    $LANG = 'tr';
}

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['lang'] = $LANG;
}

// Data shortcuts
$company           = $data['company'] ?? [];
$stats             = $data['stats'] ?? [];
$hero              = $data['hero'] ?? [];
$flagshipFeatures  = $data['flagshipFeatures'] ?? [];
$tables            = $data['tables'] ?? [];
$technicalDeepDive = $data['technicalDeepDive'] ?? [];
$faq               = $data['faq'] ?? [];

// ── String & Escaping Helpers ────────────────────────────────────────────────
if (!function_exists('e')) {
    function e($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('url_safe')) {
    function url_safe($s): string {
        $u = trim((string)$s);
        if ($u === '') return '';
        if (preg_match('#^(?:/|\#|\?|mailto:|tel:|https?://)#i', $u) || str_starts_with($u, 'wa.me/')) {
            return e($u);
        }
        if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $u)) return '';
        return e($u);
    }
}

if (!function_exists('j')) {
    function j($s): string {
        return json_encode((string)$s, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}

// ── Trilingual Tag Rendering Helpers ─────────────────────────────────────────
/**
 * Renders all 3 language representations with data-lang attributes for instant client-side switching.
 */
function t(string $tr, string $en, ?string $de = null, string $tag = 'span', string $extra = ''): string {
    $de = $de ?? $en;
    $trE = htmlspecialchars($tr, ENT_QUOTES, 'UTF-8');
    $enE = htmlspecialchars($en, ENT_QUOTES, 'UTF-8');
    $deE = htmlspecialchars($de, ENT_QUOTES, 'UTF-8');

    return "<{$tag} data-lang=\"tr\"{$extra}>{$trE}</{$tag}><{$tag} data-lang=\"en\"{$extra}>{$enE}</{$tag}><{$tag} data-lang=\"de\"{$extra}>{$deE}</{$tag}>";
}

function t_html(string $tr, string $en, ?string $de = null, string $tag = 'div', string $extra = ''): string {
    $de = $de ?? $en;
    return "<{$tag} data-lang=\"tr\"{$extra}>{$tr}</{$tag}><{$tag} data-lang=\"en\"{$extra}>{$en}</{$tag}><{$tag} data-lang=\"de\"{$extra}>{$de}</{$tag}>";
}

function t_field(array $item, string $fieldPrefix, string $tag = 'span', string $extra = ''): string {
    $tr = $item[$fieldPrefix . 'TR'] ?? ($item[$fieldPrefix] ?? '');
    $en = $item[$fieldPrefix . 'EN'] ?? $tr;
    $de = $item[$fieldPrefix . 'DE'] ?? $en;
    return t($tr, $en, $de, $tag, $extra);
}

function getLocal(array $item, string $fieldPrefix, ?string $lang = null): string {
    global $LANG;
    $l = $lang ?: $LANG;
    $suffix = strtoupper($l); // TR, EN, DE
    if (isset($item[$fieldPrefix . $suffix])) {
        return (string)$item[$fieldPrefix . $suffix];
    }
    if (isset($item[$fieldPrefix . 'TR'])) return (string)$item[$fieldPrefix . 'TR'];
    if (isset($item[$fieldPrefix . 'EN'])) return (string)$item[$fieldPrefix . 'EN'];
    return (string)($item[$fieldPrefix] ?? '');
}

function getLangUrl(string $targetLang): string {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($targetLang === 'tr') {
        return $uri;
    }
    return $uri . '?lang=' . $targetLang;
}
