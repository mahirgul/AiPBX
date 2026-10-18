<?php
/**
 * PBX → Phone logs: the syslog of desk phones (aipbx-syslogd), filtered by
 * IP or MAC and text, followed live.
 *
 * @var string $csrf
 * @var bool $receiving
 * @var bool $enabled
 * @var array<string, string> $labels MAC → "1001 · model"
 * @var string $who
 * @var string $tab   syslog | http | ftp | tftp | dhcp
 * @var string $text
 * @var list<array{time: string, ip: string, mac: string, result: string, ok: bool, file: string, detail: string}> $events
 * @var array<string, bool> $serviceOn
 */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$tabUrl = fn(string $t) => '/phone-logs?' . http_build_query(array_filter(['tab' => $t === 'syslog' ? '' : $t, 'who' => $who]));
$tabs = ['syslog' => ['fa-scroll', 'Syslog'], 'http' => ['fa-lock', 'HTTPS'], 'ftp' => ['fa-folder-open', 'FTP'],
    'tftp' => ['fa-folder', 'TFTP'], 'dhcp' => ['fa-network-wired', 'DHCP']];
?>
<div class="sub-tab-nav">
    <?php foreach ($tabs as $key => [$icon, $label]): ?>
        <a href="<?php echo $h($tabUrl($key)); ?>" class="sub-tab-btn<?php echo $tab === $key ? ' active' : ''; ?>" style="text-decoration: none;"><i class="fas <?php echo $icon; ?>"></i> <?php echo $label; ?></a>
    <?php endforeach; ?>
</div>
<?php if ($tab !== 'syslog'): ?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas <?php echo $tabs[$tab][0]; ?> u-primary"></i> <?php echo t('phonelogs.tab_title_' . $tab); ?></div>
    </div>
    <div style="padding: 0 20px 6px;" class="u-muted u-fs-12"><?php echo t('phonelogs.tab_help_' . $tab); ?></div>
    <?php if (!$serviceOn[$tab]): ?>
        <div class="alert alert-warning" style="margin: 0 20px 12px;"><i class="fas fa-exclamation-triangle"></i> <?php echo t('phonelogs.service_off'); ?> <a href="/network-services"><?php echo t('sidebar.item_network_services'); ?></a></div>
    <?php endif; ?>
    <form method="GET" action="/phone-logs" class="u-flex-gap u-mb-10" style="flex-wrap: wrap; align-items: center; padding: 0 20px;">
        <input type="hidden" name="tab" value="<?php echo $h($tab); ?>">
        <input type="text" name="who" class="form-control" style="max-width: 260px;" maxlength="64" value="<?php echo $h($who); ?>" placeholder="<?php echo $h(t('phonelogs.who_placeholder')); ?>">
        <input type="text" name="text" class="form-control" style="max-width: 260px;" maxlength="200" value="<?php echo $h($text); ?>" placeholder="<?php echo $h(t('phonelogs.text_placeholder_events')); ?>">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-rotate"></i> <?php echo t('phonelogs.refresh'); ?></button>
        <span class="text-muted u-fs-11"><?php echo sprintf(t('js.phonelogs.lines'), count($events)); ?></span>
    </form>
    <div class="table-responsive" style="padding: 0 20px 20px; max-height: 70vh; overflow: auto;">
        <table class="data-table" style="font-size: 12px;">
            <thead><tr>
                <th><?php echo t('phonelogs.col_time'); ?></th><th>IP</th><th><?php echo t('phonelogs.col_phone'); ?></th>
                <th><?php echo t('phonelogs.col_result'); ?></th><th><?php echo t('phonelogs.col_file'); ?></th><th><?php echo t('phonelogs.col_detail'); ?></th>
            </tr></thead>
            <tbody>
            <?php if ($events === []): ?>
                <?php echo uiTableEmptyRow(6, t('phonelogs.empty'), 'fa-inbox'); ?>
            <?php endif; ?>
            <?php foreach ($events as $e): ?>
                <tr>
                    <td style="white-space: nowrap;"><?php echo $h(date('d.m.Y H:i:s', strtotime($e['time']) ?: time())); ?></td>
                    <td><a href="<?php echo $h('/phone-logs?' . http_build_query(['tab' => $tab, 'who' => $e['ip']])); ?>"><?php echo $h($e['ip']); ?></a></td>
                    <td><?php if ($e['mac'] !== ''): ?><a href="<?php echo $h('/phone-logs?' . http_build_query(['tab' => $tab, 'who' => $e['mac']])); ?>"><code><?php echo $h($e['mac']); ?></code></a>
                        <?php if (isset($labels[$e['mac']])): ?><span class="u-muted"> · <?php echo $h($labels[$e['mac']]); ?></span><?php endif; ?><?php endif; ?></td>
                    <td><span class="badge <?php echo $e['ok'] ? 'badge-success' : 'badge-danger'; ?>" style="font-size: 10px;"><?php echo $h($tab === 'http' ? t('phones.result_' . $e['result'], $e['result']) : $e['result']); ?></span></td>
                    <td><code><?php echo $h($e['file']); ?></code></td>
                    <td class="u-muted" style="word-break: break-all;"><?php echo $h($e['detail']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-scroll u-primary"></i> <?php echo t('phonelogs.header_title'); ?>
            <span class="badge <?php echo $receiving ? 'badge-success' : 'badge-warning'; ?>" style="margin-left: 8px; font-size: 11px;">
                <?php echo $receiving ? t('phonelogs.receiving') : t('phonelogs.not_receiving'); ?>
            </span>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('phoneLogsHelpBox')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <div class="module-help-box" id="phoneLogsHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('phonelogs.help_title'); ?></h4>
        <?php echo t('phonelogs.help_body'); ?>
    </div>

    <?php if (!$receiving): ?>
        <div class="alert alert-warning" style="margin: 0 20px 12px;">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo $enabled ? t('phonelogs.receiver_down') : t('phonelogs.receiver_off'); ?>
            <a href="/network-services"><?php echo t('sidebar.item_network_services'); ?></a>
        </div>
    <?php endif; ?>

    <div id="phoneLogs" data-labels="<?php echo $h(json_encode($labels)); ?>" style="padding: 0 20px 20px;">
        <div class="u-flex-gap u-mb-10" style="flex-wrap: wrap; align-items: center;">
            <input type="text" id="plWho" class="form-control" style="max-width: 260px;" maxlength="64"
                   value="<?php echo $h($who); ?>" placeholder="<?php echo $h(t('phonelogs.who_placeholder')); ?>">
            <input type="text" id="plText" class="form-control" style="max-width: 260px;" maxlength="200"
                   placeholder="<?php echo $h(t('phonelogs.text_placeholder')); ?>">
            <select id="plLevel" class="form-control" style="max-width: 180px;">
                <option value="7"><?php echo t('phonelogs.level_all'); ?></option>
                <option value="4"><?php echo t('phonelogs.level_warning'); ?></option>
                <option value="3"><?php echo t('phonelogs.level_error'); ?></option>
            </select>
            <label class="u-check-label-6"><input type="checkbox" id="plLive" class="u-accent" checked> <?php echo t('phonelogs.live'); ?></label>
            <button type="button" class="btn btn-secondary btn-sm" id="plClear"><i class="fas fa-eraser"></i> <?php echo t('phonelogs.clear'); ?></button>
            <span class="text-muted u-fs-11" id="plCount"></span>
        </div>
        <div id="plBox" style="height: 65vh; overflow: auto; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 12px; line-height: 1.5;">
            <table class="table" style="margin: 0; font-size: 12px;">
                <tbody id="plRows"></tbody>
            </table>
            <div class="text-muted" id="plEmpty" style="padding: 16px;"><?php echo t('phonelogs.empty'); ?></div>
        </div>
    </div>
</div>
<script src="<?php echo asset('/assets/js/phone_logs.js'); ?>"></script>
<?php endif; ?>
