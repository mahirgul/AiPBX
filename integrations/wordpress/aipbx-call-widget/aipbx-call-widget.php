<?php
/**
 * Plugin Name:       AiPBX Call Widget
 * Plugin URI:        https://github.com/mahirgul/AiPBX
 * Description:       Adds the AiPBX "Call us" button (browser call over WebRTC and the call-back form) to your site. Create the widget in AiPBX under Integrations → Web widgets and paste its embed code here.
 * Version:           1.0.0
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
 * The stored settings: the PBX address, the widget id and where it shows.
 *
 * @return array{base: string, widget: string, everywhere: bool}
 */
function aipbx_cw_settings()
{
    $o = get_option(AIPBX_CW_OPTION, []);
    return [
        'base' => isset($o['base']) ? (string) $o['base'] : '',
        'widget' => isset($o['widget']) ? (string) $o['widget'] : '',
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

function aipbx_cw_sanitize($input)
{
    // WordPress runs the callback a second time on the first save, with the
    // already sanitized value (no 'embed' field): keep it as it is.
    if (is_array($input) && !isset($input['embed']) && isset($input['base'], $input['widget'])) {
        return $input;
    }
    $old = aipbx_cw_settings();
    $out = ['base' => $old['base'], 'widget' => $old['widget'], 'everywhere' => !empty($input['everywhere'])];
    $code = isset($input['embed']) ? trim((string) wp_unslash($input['embed'])) : '';
    if ($code === '') {
        $out['base'] = '';
        $out['widget'] = '';
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
    ?>
    <div class="wrap">
        <h1>AiPBX Call Widget</h1>
        <p><?php esc_html_e('Create a widget in AiPBX under Integrations → Web widgets, add this site to its allowed websites, and paste the embed code below.', 'aipbx-call-widget'); ?></p>
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
