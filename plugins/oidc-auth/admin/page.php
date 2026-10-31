<?php
/**
 * OIDC Auth Plugin - Custom Admin Guide View
 * Rendered below settings form on `/plugins/oidc-auth`.
 *
 * @var array<string, mixed> $plugin
 * @var array<string, mixed> $settings
 * @var array<string, mixed> $masked_settings
 * @var string $csrf_token
 */

require_once dirname(__DIR__, 3) . '/web/src/services/OidcAuthService.php';
$redirectUri = OidcAuthService::getRedirectUri();
$curDiscovery = $settings['discovery_url'] ?? '';
?>

<!-- Authorized Redirect URI Card -->
<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-link" style="color: #3b82f6;"></i>
            <?php echo htmlspecialchars(t('oidc.redirect_uri_title', 'Authorized Redirect URI (Callback URL)')); ?>
        </div>
    </div>
    <div style="padding: 20px;">
        <p style="margin-top: 0; color: var(--text-muted); font-size: 13.5px;">
            <?php echo htmlspecialchars(t('oidc.redirect_uri_help', 'Copy and paste this exact URL into the "Valid redirect URIs" or "Authorized redirect URIs" field in your Identity Provider (Keycloak, Azure AD, Authentik, Okta, etc.):')); ?>
        </p>
        <div style="display: flex; gap: 10px; align-items: center; max-width: 720px;">
            <input type="text" id="oidc_callback_url" class="form-control" readonly value="<?php echo htmlspecialchars($redirectUri); ?>" style="font-family: monospace; font-size: 13px; background: var(--bg-card); font-weight: 600;">
            <button type="button" class="btn btn-secondary" onclick="copyOidcRedirectUri()" style="white-space: nowrap; gap: 6px;">
                <i class="fas fa-copy"></i> <span id="copy_btn_text"><?php echo htmlspecialchars(t('common.copy', 'Copy')); ?></span>
            </button>
        </div>
    </div>
</div>

<!-- Test OIDC Discovery Tool -->
<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-vial" style="color: #10b981;"></i>
            <?php echo htmlspecialchars(t('oidc.test_discovery_title', 'Test OIDC Discovery / Connectivity')); ?>
        </div>
    </div>
    <div style="padding: 20px;">
        <p style="margin-top: 0; color: var(--text-muted); font-size: 13.5px;">
            <?php echo htmlspecialchars(t('oidc.test_discovery_desc', 'Verify that your AiPBX server can reach your Identity Provider and retrieve the OpenID Connect discovery document before saving or testing sign-in.')); ?>
        </p>
        <form method="POST" style="display: flex; gap: 10px; align-items: center; max-width: 720px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="test_oidc_discovery" value="1">
            <input type="text" name="test_discovery_url" class="form-control" placeholder="https://sso.example.com/realms/master/.well-known/openid-configuration" value="<?php echo htmlspecialchars($curDiscovery); ?>" style="font-size: 13px;">
            <button type="submit" class="btn btn-primary" style="white-space: nowrap; gap: 6px;">
                <i class="fas fa-search"></i> <?php echo htmlspecialchars(t('oidc.btn_test_discovery', 'Test Discovery')); ?>
            </button>
        </form>
    </div>
</div>

<!-- IdP Setup Guides Card -->
<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-book-open" style="color: #f59e0b;"></i>
            <?php echo htmlspecialchars(t('oidc.setup_guides_title', 'Identity Provider Configuration Guides')); ?>
        </div>
    </div>
    <div style="padding: 20px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
            <!-- Keycloak -->
            <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; background: var(--bg-card);">
                <h4 style="margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px; font-size: 15px;">
                    <i class="fas fa-key" style="color: #ef4444;"></i> Keycloak
                </h4>
                <ol style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-muted); line-height: 1.6;">
                    <li>Navigate to your <strong>Realm</strong> &rarr; <strong>Clients</strong> &rarr; <strong>Create client</strong>.</li>
                    <li>Client type: <strong>OpenID Connect</strong>, Client ID: <code>aipbx</code>.</li>
                    <li>Turn <strong>Client authentication</strong> to <strong>ON</strong>.</li>
                    <li>Add <code><?php echo htmlspecialchars($redirectUri); ?></code> to <strong>Valid redirect URIs</strong>.</li>
                    <li>Under the <strong>Credentials</strong> tab, copy the <strong>Client Secret</strong>.</li>
                    <li>Discovery URL: <code>https://&lt;keycloak-host&gt;/realms/&lt;realm&gt;/.well-known/openid-configuration</code></li>
                </ol>
            </div>

            <!-- Microsoft Entra ID (Azure AD) -->
            <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; background: var(--bg-card);">
                <h4 style="margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px; font-size: 15px;">
                    <i class="fab fa-microsoft" style="color: #0078d4;"></i> Microsoft Entra ID (Azure AD)
                </h4>
                <ol style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-muted); line-height: 1.6;">
                    <li>Go to <strong>Microsoft Entra admin center</strong> &rarr; <strong>App registrations</strong> &rarr; <strong>New registration</strong>.</li>
                    <li>Platform: <strong>Web</strong>, Redirect URI: <code><?php echo htmlspecialchars($redirectUri); ?></code>.</li>
                    <li>Copy the <strong>Application (client) ID</strong> from Overview.</li>
                    <li>Go to <strong>Certificates & secrets</strong> &rarr; <strong>New client secret</strong>.</li>
                    <li>Discovery URL: <code>https://login.microsoftonline.com/&lt;tenant-id&gt;/v2.0/.well-known/openid-configuration</code></li>
                </ol>
            </div>

            <!-- Authentik -->
            <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; background: var(--bg-card);">
                <h4 style="margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px; font-size: 15px;">
                    <i class="fas fa-shield-alt" style="color: #fd7e14;"></i> Authentik
                </h4>
                <ol style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-muted); line-height: 1.6;">
                    <li>In Authentik Admin, go to <strong>Applications</strong> &rarr; <strong>Providers</strong> &rarr; <strong>Create Provider</strong>.</li>
                    <li>Select <strong>OAuth2/OpenID Provider</strong>.</li>
                    <li>Client type: <strong>Confidential</strong>, Redirect URI: <code><?php echo htmlspecialchars($redirectUri); ?></code>.</li>
                    <li>Under <strong>Advanced protocol settings</strong>, copy the Discovery URL: <code>https://&lt;authentik&gt;/application/o/&lt;app&gt;/.well-known/openid-configuration</code>.</li>
                </ol>
            </div>

            <!-- Okta -->
            <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; background: var(--bg-card);">
                <h4 style="margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px; font-size: 15px;">
                    <i class="fas fa-circle-notch" style="color: #007dc1;"></i> Okta
                </h4>
                <ol style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--text-muted); line-height: 1.6;">
                    <li>Go to <strong>Applications</strong> &rarr; <strong>Create App Integration</strong> &rarr; <strong>OIDC - OpenID Connect</strong>.</li>
                    <li>Application type: <strong>Web Application</strong>.</li>
                    <li>Sign-in redirect URIs: <code><?php echo htmlspecialchars($redirectUri); ?></code>.</li>
                    <li>Copy Client ID and Client Secret from the General tab.</li>
                    <li>Discovery URL: <code>https://&lt;okta-domain&gt;/.well-known/openid-configuration</code></li>
                </ol>
            </div>
        </div>
    </div>
</div>

<script>
function copyOidcRedirectUri() {
    var input = document.getElementById('oidc_callback_url');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(function() {
        var btnText = document.getElementById('copy_btn_text');
        if (btnText) {
            var orig = btnText.innerText;
            btnText.innerText = '<?php echo htmlspecialchars(t('common.copied', 'Copied!')); ?>';
            setTimeout(function() { btnText.innerText = orig; }, 2000);
        }
    });
}
</script>
