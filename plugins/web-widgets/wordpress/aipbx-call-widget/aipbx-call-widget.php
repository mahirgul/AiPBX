<?php
/**
 * Plugin Name:       AiPBX Call Widget & API Connector
 * Plugin URI:        https://github.com/mahirgul/AiPBX
 * Description:       Adds the AiPBX "Call us" button (browser call over WebRTC and the call-back form) to your WordPress site. Supports pasting the embed code or fetching widgets directly via AiPBX REST API v1 (/api/v1/web-widgets).
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            AiPBX
 * License:           GPL-3.0-or-later
 * Text Domain:       aipbx-call-widget
 */

if (!defined('ABSPATH')) {
    exit;
}

const AIPBX_CW_OPTION = 'aipbx_call_widget';

/**
 * The stored settings: the PBX address, the widget id, optional API token, and where it shows.
 *
 * @return array{base: string, widget: string, api_token: string, everywhere: bool}
 */
function aipbx_cw_settings()
{
    $o = get_option(AIPBX_CW_OPTION, []);
    return [
        'base'       => isset($o['base']) ? (string) $o['base'] : '',
        'widget'     => isset($o['widget']) ? (string) $o['widget'] : '',
        'api_token'  => isset($o['api_token']) ? (string) $o['api_token'] : '',
        'everywhere' => !isset($o['everywhere']) || !empty($o['everywhere']),
    ];
}

/**
 * Reads the PBX address and the widget id from the embed code AiPBX shows:
 * <script src="https://pbx.example.com/widget.js" data-widget="w_…" async></script>
 *
 * @return array{base: string, widget: string}|null
 */
function aipbx_cw_parse_embed($code)
{
    if (!preg_match('#src=["\'](https://[A-Za-z0-9.-]+(?::\d+)?)/widget\.js["\']#', (string) $code, $src)) {
        return null;
    }
    if (!preg_match('#data-widget=["\'](w_[a-z0-9]{8,20})["\']#', (string) $code, $id)) {
        return null;
    }
    return ['base' => $src[1], 'widget' => $id[1]];
}

/**
 * Fetch available widgets from AiPBX REST API (/api/v1/web-widgets) when base URL and API token are configured.
 *
 * @return list<array{token: string, name: string, number: string}>
 */
function aipbx_cw_fetch_remote_widgets($base, $apiToken)
{
    if ($base === '' || $apiToken === '' || !function_exists('wp_remote_get')) {
        return [];
    }
    $res = wp_remote_get(rtrim($base, '/') . '/api/v1/web-widgets', [
        'timeout' => 5,
        'headers' => [
            'Authorization' => 'Bearer ' . $apiToken,
            'Accept'        => 'application/json',
        ],
    ]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        return [];
    }
    $body = json_decode((string) wp_remote_retrieve_body($res), true);
    if (!is_array($body) || empty($body['success']) || !is_array($body['data'])) {
        return [];
    }
    $out = [];
    foreach ($body['data'] as $w) {
        $token = (string) ($w['public_id'] ?? $w['token'] ?? '');
        if ($token !== '') {
            $out[] = [
                'token'  => $token,
                'name'   => (string) ($w['name'] ?? $token),
                'number' => (string) ($w['dest_value'] ?? $w['number'] ?? ''),
            ];
        }
    }
    return $out;
}

function aipbx_cw_sanitize($input)
{
    // WordPress runs the callback a second time on the first save, with the
    // already sanitized value (no 'embed' field): validate and keep it.
    if (is_array($input) && !isset($input['embed']) && isset($input['base'], $input['widget'])) {
        $base = (string) $input['base'];
        $widget = (string) $input['widget'];
        $apiToken = isset($input['api_token']) ? trim((string) $input['api_token']) : '';
        $everywhere = !empty($input['everywhere']);
        if ($base !== '' && $widget !== ''
            && preg_match('#^https://[A-Za-z0-9.-]+(?::\d+)?$#', $base)
            && preg_match('#^w_[a-z0-9]{8,20}$#', $widget)) {
            return ['base' => $base, 'widget' => $widget, 'api_token' => $apiToken, 'everywhere' => $everywhere];
        }
        return ['base' => $base, 'widget' => '', 'api_token' => $apiToken, 'everywhere' => $everywhere];
    }
    $old = aipbx_cw_settings();
    $submittedToken = isset($input['api_token']) ? trim((string) wp_unslash($input['api_token'])) : '';
    $apiToken = $submittedToken !== '' ? $submittedToken : $old['api_token'];
    $out = [
        'base'       => $old['base'],
        'widget'     => $old['widget'],
        'api_token'  => $apiToken,
        'everywhere' => !empty($input['everywhere']),
    ];

    if (!empty($input['pbx_base']) && preg_match('#^https://[A-Za-z0-9.-]+(?::\d+)?$#', trim((string) $input['pbx_base']))) {
        $out['base'] = rtrim(trim((string) $input['pbx_base']), '/');
    }
    if (!empty($input['selected_widget']) && preg_match('#^w_[a-z0-9]{8,20}$#', trim((string) $input['selected_widget']))) {
        $out['widget'] = trim((string) $input['selected_widget']);
        return $out;
    }

    $code = isset($input['embed']) ? trim((string) wp_unslash($input['embed'])) : '';
    if ($code === '') {
        if (empty($input['selected_widget'])) {
            $out['widget'] = '';
        }
    } else {
        $parsed = aipbx_cw_parse_embed($code);
        if ($parsed === null) {
            add_settings_error(AIPBX_CW_OPTION, 'embed', __('This is not an AiPBX embed code. Copy it from Integrations → Web widgets in AiPBX.', 'aipbx-call-widget'));
        } else {
            $out = array_merge($out, $parsed);
        }
    }
    return $out;
}

function aipbx_cw_embed_code()
{
    $s = aipbx_cw_settings();
    if ($s['base'] === '' || $s['widget'] === '') {
        return '';
    }
    return '<script src="' . esc_url($s['base'] . '/widget.js') . '" data-widget="' . esc_attr($s['widget']) . '" async></script>';
}

// The button on every page (the default), printed once in the footer.
add_action('wp_footer', function () {
    $s = aipbx_cw_settings();
    if ($s['everywhere']) {
        echo aipbx_cw_embed_code() . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }
});

// [aipbx_call_widget]: the button only on the pages that carry the shortcode.
add_shortcode('aipbx_call_widget', function () {
    return aipbx_cw_embed_code();
});

add_action('admin_init', function () {
    register_setting('aipbx_call_widget', AIPBX_CW_OPTION, ['sanitize_callback' => 'aipbx_cw_sanitize']);
});

add_action('admin_menu', function () {
    add_options_page('AiPBX Call Widget', 'AiPBX Call Widget', 'manage_options', 'aipbx-call-widget', 'aipbx_cw_settings_page');
});

function aipbx_cw_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $s = aipbx_cw_settings();
    $remoteWidgets = aipbx_cw_fetch_remote_widgets($s['base'], $s['api_token']);
    ?>
    <div class="wrap">
        <h1>AiPBX Call Widget</h1>
        <p><?php esc_html_e('Create a widget in AiPBX under Integrations → Web widgets, add this site to its allowed websites, and paste the embed code below (or connect via REST API v1).', 'aipbx-call-widget'); ?></p>
        <form method="post" action="options.php">
            <?php settings_fields('aipbx_call_widget'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="aipbx-cw-embed"><?php esc_html_e('Embed code', 'aipbx-call-widget'); ?></label></th>
                    <td>
                        <textarea id="aipbx-cw-embed" name="<?php echo esc_attr(AIPBX_CW_OPTION); ?>[embed]" rows="3" class="large-text code"><?php echo esc_textarea(aipbx_cw_embed_code()); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="aipbx-cw-base"><?php esc_html_e('PBX HTTPS URL (Optional API v1)', 'aipbx-call-widget'); ?></label></th>
                    <td>
                        <input type="url" id="aipbx-cw-base" name="<?php echo esc_attr(AIPBX_CW_OPTION); ?>[pbx_base]" value="<?php echo esc_attr($s['base']); ?>" class="regular-text" placeholder="https://pbx.example.com">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="aipbx-cw-token"><?php esc_html_e('AiPBX API Key (Optional)', 'aipbx-call-widget'); ?></label></th>
                    <td>
                        <input type="password" id="aipbx-cw-token" name="<?php echo esc_attr(AIPBX_CW_OPTION); ?>[api_token]" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo $s['api_token'] !== '' ? esc_attr__('•••••••• (saved — leave blank to keep)', 'aipbx-call-widget') : 'apbx_...'; ?>">
                        <p class="description"><?php esc_html_e('Optional: provide an API key with pbx:read scope to pick widgets directly from your AiPBX server.', 'aipbx-call-widget'); ?></p>
                    </td>
                </tr>
                <?php if (!empty($remoteWidgets)): ?>
                <tr>
                    <th scope="row"><label for="aipbx-cw-select"><?php esc_html_e('Select Widget from PBX', 'aipbx-call-widget'); ?></label></th>
                    <td>
                        <select id="aipbx-cw-select" name="<?php echo esc_attr(AIPBX_CW_OPTION); ?>[selected_widget]">
                            <option value=""><?php esc_html_e('— Keep current —', 'aipbx-call-widget'); ?></option>
                            <?php foreach ($remoteWidgets as $rw): ?>
                                <option value="<?php echo esc_attr($rw['token']); ?>" <?php selected($s['widget'], $rw['token']); ?>>
                                    <?php echo esc_html($rw['name'] . ' (' . $rw['number'] . ' — ' . $rw['token'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Where', 'aipbx-call-widget'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(AIPBX_CW_OPTION); ?>[everywhere]" value="1" <?php checked($s['everywhere']); ?>>
                            <?php esc_html_e('Show the button on every page', 'aipbx-call-widget'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Untick to show it only on pages with the [aipbx_call_widget] shortcode.', 'aipbx-call-widget'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
