<?php
/** @var array $agents @var ?array $edit @var array $modules @var string $csrf_token */
use PBX\Destinations\DestinationRegistry;

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$labelCache = [];
$destLabel = function (string $type, string $id) use (&$labelCache): string {
    if ($type === '') {
        return '—';
    }
    $mod = DestinationRegistry::getModule($type);
    return ($mod ? $mod->getName() : $type) . ($id !== '' ? ': ' . (DestinationRegistry::resolveLabel($type, $id, $labelCache) ?? $id) : '');
};
$destPair = function (string $prefix, string $label, bool $optional) use ($edit, $modules, $h): string {
    $type = (string) ($edit[$prefix . '_type'] ?? '');
    $out = '<div class="form-group"><label class="form-label">' . $h($label) . '</label>'
        . '<select name="' . $prefix . '_type" id="agent_' . $prefix . '_type" class="form-control" data-dest-target="agent_' . $prefix . '_id" data-dest-selected="' . $h($edit[$prefix . '_id'] ?? '') . '">';
    if ($optional) {
        $out .= '<option value="">' . $h(t('ai_agents.no_handover')) . '</option>';
    }
    foreach ($modules as $m) {
        $out .= '<option value="' . $h($m['key']) . '"' . ($m['key'] === $type ? ' selected' : '') . '>' . $h($m['name']) . '</option>';
    }
    return $out . '</select></div><div class="form-group"><label class="form-label">&nbsp;</label>'
        . '<select name="' . $prefix . '_id" id="agent_' . $prefix . '_id" class="form-control"></select></div>';
};
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-satellite', t('ai_agents.title'), 'aiAgentsHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>
            <a href="/ai-agents?new=1" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> <?php echo t('ai_agents.btn_new'); ?></a>
<?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('aiAgentsHelpBox', t('ai_agents.help_title')); ?>
        <?php echo t('ai_agents.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>
    <?php if (!empty($privacy_blocked)): ?>
        <div class="alert alert-warning u-mt-12 u-mb-20 u-fs-12"><i class="fas fa-shield-alt"></i> <?php echo $h(t('ai_agents.privacy_cloud_blocked_notice', 'Cross-border cloud AI data transfer lock is active: external SIP agent calls are paused and routed to the handover or fallback destination.')); ?></div>
    <?php endif; ?>
    <?php echo uiTableOpen([
        t('ai_agents.col_title'),
        t('ai_agents.col_address'),
        t('ai_agents.col_limits'),
        t('ai_agents.col_handover'),
        t('ai_agents.col_fallback'),
        t('ai_agents.col_active'),
        '',
    ], ['frame' => 'u-card-pad']); ?>
            <?php if (!$agents): ?><?php echo uiTableEmptyRow(7, t('ai_agents.none')); ?><?php endif; ?>
            <?php foreach ($agents as $a): ?>
                <tr>
                    <td><strong><?php echo $h($a['title']); ?></strong></td>
                    <td class="u-fs-12 u-mono"><?php echo $h($a['sip_uri']); ?> <span class="badge badge-info"><?php echo $h(strtoupper((string) $a['transport'])); ?></span></td>
                    <td class="u-fs-12"><?php echo $h(sprintf(t('ai_agents.limits'), (int) $a['max_concurrent'], (int) $a['max_minutes'])); ?></td>
                    <td class="u-fs-12"><?php echo $h($destLabel((string) $a['handover_dest_type'], (string) $a['handover_dest_id'])); ?></td>
                    <td class="u-fs-12"><?php echo $h($destLabel((string) $a['fallback_dest_type'], (string) $a['fallback_dest_id'])); ?></td>
                    <td><?php echo uiStatusBadge((int) $a['is_active'] ? 'on' : 'off', ['on' => 'success', 'off' => 'secondary'], 'secondary', (int) $a['is_active'] ? t('common.active') : t('common.passive')); ?></td>
                    <td class="u-ta-right u-nowrap">
                        <a href="/ai-agents?edit=<?php echo (int) $a['id']; ?>" class="btn btn-icon btn-secondary btn-sm" title="<?php echo $h(t('common.edit')); ?>"><i class="fas fa-pen"></i></a>
                        <?php echo uiDeleteForm((int) $a['id'], 'agent_id', 'delete_agent', t('ai_agents.confirm_delete')); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
</div>

<?php if ($edit !== null): ?>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-pen u-primary"></i> <?php echo $h((int) $edit['id'] > 0 ? $edit['title'] : t('ai_agents.btn_new')); ?></div></div>
    <form method="POST" action="/ai-agents" autocomplete="off" class="u-card-pad">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">
        <div class="u-grid-2">
            <div class="form-group"><label class="form-label"><?php echo t('ai_agents.field_title'); ?></label>
                <input type="text" name="title" class="form-control" maxlength="100" required value="<?php echo $h($edit['title']); ?>"></div>
            <div class="form-group"><label class="form-label"><?php echo t('ai_agents.field_uri'); ?></label>
                <input type="text" name="sip_uri" class="form-control u-mono" maxlength="255" required placeholder="sip:agent@voice.example.com:5060" value="<?php echo $h($edit['sip_uri']); ?>">
                <small class="u-hint u-fs-11"><?php echo t('ai_agents.uri_help'); ?></small></div>
            <div class="form-group"><label class="form-label"><?php echo t('ai_agents.field_transport'); ?></label>
                <select name="transport" class="form-control">
                    <?php foreach (AiAgentService::TRANSPORTS as $tr): ?><option value="<?php echo $tr; ?>"<?php echo $edit['transport'] === $tr ? ' selected' : ''; ?>><?php echo strtoupper($tr); ?></option><?php endforeach; ?>
                </select></div>
            <div class="form-group"><label class="form-label"><?php echo t('ai_agents.field_auth'); ?></label>
                <div class="u-flex-gap">
                    <input type="text" name="auth_username" class="form-control" maxlength="100" placeholder="<?php echo $h(t('ai_agents.auth_user')); ?>" value="<?php echo $h($edit['auth_username']); ?>">
                    <input type="password" name="auth_password" class="form-control" maxlength="200" autocomplete="new-password" placeholder="<?php echo $h(!empty($edit['auth_password']) ? t('ai_agents.auth_keep') : t('ai_agents.auth_pass')); ?>">
                </div>
                <small class="u-hint u-fs-11"><?php echo t('ai_agents.auth_help'); ?></small></div>
            <div class="form-group"><label class="form-label"><?php echo t('ai_agents.field_max'); ?></label>
                <input type="number" name="max_concurrent" class="form-control" min="1" max="100" value="<?php echo (int) $edit['max_concurrent']; ?>"></div>
            <div class="form-group"><label class="form-label"><?php echo t('ai_agents.field_minutes'); ?></label>
                <input type="number" name="max_minutes" class="form-control" min="1" max="240" value="<?php echo (int) $edit['max_minutes']; ?>"></div>
            <div class="form-group"><label class="form-label"><?php echo t('ai_agents.field_ring'); ?></label>
                <input type="number" name="ring_seconds" class="form-control" min="5" max="120" value="<?php echo (int) $edit['ring_seconds']; ?>"></div>
            <div class="form-group"><label class="u-check-label-6 u-mt-32">
                <input type="checkbox" name="is_active" value="1" class="u-accent"<?php echo (int) $edit['is_active'] ? ' checked' : ''; ?>> <?php echo t('common.active'); ?></label></div>
            <?php echo $destPair('handover_dest', t('ai_agents.field_handover'), true); ?>
            <?php echo $destPair('fallback_dest', t('ai_agents.field_fallback'), false); ?>
        </div>
        <p class="u-hint u-fs-11"><?php echo t('ai_agents.dest_help'); ?></p>
        <div class="u-flex-gap">
            <?php echo uiSaveButton(null, 'name="save_agent" value="1"'); ?>
            <a href="/ai-agents" class="btn btn-secondary"><i class="fas fa-times"></i> <?php echo t('common.cancel'); ?></a>
        </div>
    </form>
</div>
<script src="<?php echo asset('/assets/js/destinations_helper.js'); ?>"></script>
<?php endif; ?>
