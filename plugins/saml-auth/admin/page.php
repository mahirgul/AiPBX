<?php
/**
 * SAML 2.0 Auth Plugin - Custom Admin Guide View
 * Rendered below settings form on `/plugins/saml-auth`.
 *
 * @var array<string, mixed> $plugin
 * @var array<string, mixed> $settings
 * @var array<string, mixed> $masked_settings
 * @var string $csrf_token
 */

require_once dirname(__DIR__, 3) . '/web/src/services/SamlAuthService.php';

$spEntityId = SamlAuthService::getSpEntityId();
$acsUrl = SamlAuthService::getAcsUrl();
$metadataUrl = SamlAuthService::getMetadataUrl();
?>

<!-- Service Provider (SP) Information Card -->
<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-link" style="color: #0284c7;"></i>
            <?php echo htmlspecialchars(t('saml.sp_details_title', 'AiPBX Service Provider (SP) Endpoints')); ?>
        </div>
    </div>
    <div style="padding: 20px;">
        <p style="margin-top: 0; color: var(--text-muted); font-size: 13.5px;">
            <?php echo htmlspecialchars(t('saml.sp_details_desc', 'Configure these values into your Identity Provider (Microsoft Entra ID, Okta, Keycloak, Authentik, ADFS, etc.):')); ?>
        </p>

        <div style="display: flex; flex-direction: column; gap: 14px; max-width: 800px;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; text-transform: uppercase; color: var(--text-muted);">
                    <?php echo htmlspecialchars(t('saml.sp_entity_id_label', 'Identifier (SP Entity ID)')); ?>
                </label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="saml_sp_entity_id" class="form-control" readonly value="<?php echo htmlspecialchars($spEntityId); ?>" style="font-family: monospace; font-size: 13px; background: var(--bg-card); font-weight: 600;">
                    <button type="button" class="btn btn-secondary" onclick="copySamlField('saml_sp_entity_id', this)" style="white-space: nowrap; gap: 6px;">
                        <i class="fas fa-copy"></i> <span><?php echo htmlspecialchars(t('common.copy', 'Copy')); ?></span>
                    </button>
                </div>
            </div>

            <div>
                <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; text-transform: uppercase; color: var(--text-muted);">
                    <?php echo htmlspecialchars(t('saml.acs_url_label', 'Reply URL (Assertion Consumer Service / ACS URL)')); ?>
                </label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="saml_acs_url" class="form-control" readonly value="<?php echo htmlspecialchars($acsUrl); ?>" style="font-family: monospace; font-size: 13px; background: var(--bg-card); font-weight: 600;">
                    <button type="button" class="btn btn-secondary" onclick="copySamlField('saml_acs_url', this)" style="white-space: nowrap; gap: 6px;">
                        <i class="fas fa-copy"></i> <span><?php echo htmlspecialchars(t('common.copy', 'Copy')); ?></span>
                    </button>
                </div>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; margin-top: 6px;">
                <a href="/auth/saml/metadata" target="_blank" class="btn btn-outline-primary" style="gap: 6px; font-size: 12.5px;">
                    <i class="fas fa-file-code"></i> <?php echo htmlspecialchars(t('saml.btn_view_metadata', 'Download / View SP Metadata XML')); ?>
                </a>
                <span style="font-size: 12px; color: var(--text-muted);">
                    (<code><?php echo htmlspecialchars($metadataUrl); ?></code>)
                </span>
            </div>
        </div>
    </div>
</div>

<!-- IdP Metadata Auto-Parser / Importer Tool -->
<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-magic" style="color: #10b981;"></i>
            <?php echo htmlspecialchars(t('saml.import_idp_title', 'Quick Setup: Parse & Import IdP XML Metadata')); ?>
        </div>
    </div>
    <div style="padding: 20px;">
        <p style="margin-top: 0; color: var(--text-muted); font-size: 13.5px;">
            <?php echo htmlspecialchars(t('saml.import_idp_desc', 'Paste the XML Federation Metadata downloaded from your Identity Provider. AiPBX will automatically extract the Entity ID, SingleSignOnService URL, and X.509 Certificate and populate the form fields above.')); ?>
        </p>

        <form method="POST" style="max-width: 800px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="parse_saml_idp_metadata" value="1">

            <div style="margin-bottom: 12px;">
                <textarea name="idp_metadata_xml" class="form-control" rows="5" placeholder="Paste <?xml version='1.0' ... ?> <EntityDescriptor xmlns='urn:oasis:names:tc:SAML:2.0:metadata' ... here" style="font-family: monospace; font-size: 12px;"></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="white-space: nowrap; gap: 6px;">
                <i class="fas fa-file-import"></i> <?php echo htmlspecialchars(t('saml.btn_parse_metadata', 'Parse & Populate Settings')); ?>
            </button>
        </form>
    </div>
</div>

<!-- Identity Provider Integration Guides -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-book-open" style="color: #f59e0b;"></i>
            <?php echo htmlspecialchars(t('saml.guides_title', 'Identity Provider Configuration Guides')); ?>
        </div>
    </div>
    <div style="padding: 20px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px;">
            <!-- Microsoft Entra ID Card -->
            <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; background: var(--bg-card);">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <i class="fab fa-microsoft" style="color: #0078d4; font-size: 20px;"></i>
                    <strong style="font-size: 14px;">Microsoft Entra ID (Azure AD)</strong>
                </div>
                <ol style="margin: 0; padding-left: 18px; font-size: 13px; line-height: 1.6; color: var(--text-muted);">
                    <li>Go to <strong>Microsoft Entra admin center &gt; Enterprise applications</strong>.</li>
                    <li>Click <strong>New application &gt; Create your own application &gt; Integrate any other application (Non-gallery)</strong>.</li>
                    <li>Open <strong>Single sign-on</strong> and choose <strong>SAML</strong>.</li>
                    <li>Set <strong>Identifier (Entity ID)</strong> to: <code style="word-break: break-all;"><?php echo htmlspecialchars($spEntityId); ?></code></li>
                    <li>Set <strong>Reply URL (ACS URL)</strong> to: <code style="word-break: break-all;"><?php echo htmlspecialchars($acsUrl); ?></code></li>
                    <li>Download <strong>Federation Metadata XML</strong> and paste it into the parser above!</li>
                </ol>
            </div>

            <!-- Okta SAML Card -->
            <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; background: var(--bg-card);">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <i class="fas fa-shield-alt" style="color: #007dc1; font-size: 20px;"></i>
                    <strong style="font-size: 14px;">Okta SAML 2.0</strong>
                </div>
                <ol style="margin: 0; padding-left: 18px; font-size: 13px; line-height: 1.6; color: var(--text-muted);">
                    <li>In Okta Admin Console, navigate to <strong>Applications &gt; Create App Integration</strong>.</li>
                    <li>Select <strong>SAML 2.0</strong> and click <strong>Next</strong>.</li>
                    <li>Set <strong>Single sign-on URL</strong> to: <code style="word-break: break-all;"><?php echo htmlspecialchars($acsUrl); ?></code></li>
                    <li>Set <strong>Audience URI (SP Entity ID)</strong> to: <code style="word-break: break-all;"><?php echo htmlspecialchars($spEntityId); ?></code></li>
                    <li>Set <strong>Name ID format</strong> to <code>EmailAddress</code>.</li>
                    <li>Copy Okta <strong>Metadata URL</strong> or XML into the parser tool above.</li>
                </ol>
            </div>

            <!-- Keycloak Card -->
            <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; background: var(--bg-card);">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <i class="fas fa-key" style="color: #ec5826; font-size: 20px;"></i>
                    <strong style="font-size: 14px;">Keycloak SAML</strong>
                </div>
                <ol style="margin: 0; padding-left: 18px; font-size: 13px; line-height: 1.6; color: var(--text-muted);">
                    <li>Navigate to <strong>Clients &gt; Create client</strong>.</li>
                    <li>Client type: <strong>SAML</strong>. Client ID: <code style="word-break: break-all;"><?php echo htmlspecialchars($spEntityId); ?></code></li>
                    <li>Set <strong>Master SAML Processing URL</strong> to: <code style="word-break: break-all;"><?php echo htmlspecialchars($acsUrl); ?></code></li>
                    <li>Enable <strong>Sign Assertions</strong> and <strong>Force POST Binding</strong>.</li>
                    <li>Export Keycloak SAML keys/metadata into AiPBX.</li>
                </ol>
            </div>

            <!-- Authentik Card -->
            <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; background: var(--bg-card);">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <i class="fas fa-id-badge" style="color: #fd4b2d; font-size: 20px;"></i>
                    <strong style="font-size: 14px;">Authentik SAML Provider</strong>
                </div>
                <ol style="margin: 0; padding-left: 18px; font-size: 13px; line-height: 1.6; color: var(--text-muted);">
                    <li>Go to <strong>Applications &gt; Providers &gt; Create Provider</strong> and choose <strong>SAML Provider</strong>.</li>
                    <li>Set <strong>ACS URL</strong> to: <code style="word-break: break-all;"><?php echo htmlspecialchars($acsUrl); ?></code></li>
                    <li>Set <strong>Audience</strong> to: <code style="word-break: break-all;"><?php echo htmlspecialchars($spEntityId); ?></code></li>
                    <li>Download provider metadata XML or copy X.509 certificate.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<script>
function copySamlField(fieldId, btn) {
    const input = document.getElementById(fieldId);
    if (!input) return;
    navigator.clipboard.writeText(input.value).then(() => {
        const span = btn.querySelector('span');
        const oldText = span ? span.innerText : '';
        if (span) span.innerText = '<?php echo htmlspecialchars(t('common.copied', 'Copied!')); ?>';
        btn.classList.remove('btn-secondary');
        btn.classList.add('btn-success');
        setTimeout(() => {
            if (span) span.innerText = oldText;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-secondary');
        }, 2000);
    }).catch(err => {
        input.select();
        document.execCommand('copy');
    });
}
</script>
