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
<div class="card tabbed-page">
    <?php echo uiPageHeader('fa-scroll', t('sidebar.item_phone_logs')); ?>
<div class="sub-tab-nav">
    <?php foreach ($tabs as $key => [$icon, $label]): ?>
        <a href="<?php echo $h($tabUrl($key)); ?>" class="sub-tab-btn<?php echo $tab === $key ? ' active' : ''; ?> u-text-decoration-none"><i class="fas <?php echo $icon; ?>"></i> <?php echo $label; ?></a>
    <?php endforeach; ?>
</div>
<?php if ($tab !== 'syslog'): ?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas <?php echo $tabs[$tab][0]; ?> u-primary"></i> <?php echo t('phonelogs.tab_title_' . $tab); ?></div>
    </div>
    <div class="u-muted u-fs-12 u-p-0-20-8"><?php echo t('phonelogs.tab_help_' . $tab); ?></div>
    <?php if (!$serviceOn[$tab]): ?>
        <div class="alert alert-warning u-card-text"><i class="fas fa-exclamation-triangle"></i> <?php echo t('phonelogs.service_off'); ?> <a href="/network-services"><?php echo t('sidebar.item_network_services'); ?></a></div>
    <?php endif; ?>
    <form method="GET" action="/phone-logs" class="u-flex-gap u-mb-10 u-wrap u-ai-center u-p-0-20">
        <input type="hidden" name="tab" value="<?php echo $h($tab); ?>">
        <input type="text" name="who" class="form-control u-maxw-260" maxlength="64" value="<?php echo $h($who); ?>" placeholder="<?php echo $h(t('phonelogs.who_placeholder')); ?>">
        <input type="text" name="text" class="form-control u-maxw-260" maxlength="200" value="<?php echo $h($text); ?>" placeholder="<?php echo $h(t('phonelogs.text_placeholder_events')); ?>">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-rotate"></i> <?php echo t('phonelogs.refresh'); ?></button>
        <span class="text-muted u-fs-11"><?php echo sprintf(t('js.phonelogs.lines'), count($events)); ?></span>
    </form>
    <?php echo uiTableOpen([
        t('phonelogs.col_time'),
        'IP',
        t('phonelogs.col_phone'),
        t('phonelogs.col_result'),
        t('phonelogs.col_file'),
        t('phonelogs.col_detail'),
    ], ['frame' => 'u-card-pad u-maxh-70vh u-of-auto']); ?>
            <?php if ($events === []): ?>
                <?php echo uiTableEmptyRow(6, t('phonelogs.empty'), 'fa-inbox'); ?>
            <?php endif; ?>
            <?php foreach ($events as $e): ?>
                <tr>
                    <td class="u-nowrap"><?php echo $h(date('d.m.Y H:i:s', strtotime($e['time']) ?: time())); ?></td>
                    <td><a href="<?php echo $h('/phone-logs?' . http_build_query(['tab' => $tab, 'who' => $e['ip']])); ?>"><?php echo $h($e['ip']); ?></a></td>
                    <td><?php if ($e['mac'] !== ''): ?><a href="<?php echo $h('/phone-logs?' . http_build_query(['tab' => $tab, 'who' => $e['mac']])); ?>"><code><?php echo $h($e['mac']); ?></code></a>
                        <?php if (isset($labels[$e['mac']])): ?><span class="u-muted"> · <?php echo $h($labels[$e['mac']]); ?></span><?php endif; ?><?php endif; ?></td>
                    <td><span class="badge <?php echo $e['ok'] ? 'badge-success' : 'badge-danger'; ?> u-fs-10"><?php echo $h($tab === 'http' ? t('phones.result_' . $e['result'], $e['result']) : $e['result']); ?></span></td>
                    <td><code><?php echo $h($e['file']); ?></code></td>
                    <td class="u-muted u-word-break-break-all"><?php echo $h($e['detail']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-scroll u-primary"></i> <?php echo t('phonelogs.header_title'); ?>
            <span class="badge <?php echo $receiving ? 'badge-success' : 'badge-warning'; ?> u-ml-8 u-fs-11">
                <?php echo $receiving ? t('phonelogs.receiving') : t('phonelogs.not_receiving'); ?>
            </span>
        </div>
        <?php echo uiHelpButton('phoneLogsHelpBox'); ?>
    </div>

    <?php echo uiHelpBoxOpen('phoneLogsHelpBox', t('phonelogs.help_title')); ?>
        <?php echo t('phonelogs.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

    <?php if (!$receiving): ?>
        <div class="alert alert-warning u-card-text">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo $enabled ? t('phonelogs.receiver_down') : t('phonelogs.receiver_off'); ?>
            <a href="/network-services"><?php echo t('sidebar.item_network_services'); ?></a>
        </div>
    <?php endif; ?>

    <div id="phoneLogs" data-labels="<?php echo $h(json_encode($labels)); ?>" class="u-card-pad">
        <div class="u-flex-gap u-mb-10 u-wrap u-ai-center">
            <input type="text" id="plWho" class="form-control u-maxw-260" maxlength="64"
                   value="<?php echo $h($who); ?>" placeholder="<?php echo $h(t('phonelogs.who_placeholder')); ?>">
            <input type="text" id="plText" class="form-control u-maxw-260" maxlength="200"
                   placeholder="<?php echo $h(t('phonelogs.text_placeholder')); ?>">
            <select id="plLevel" class="form-control u-maxw-180">
                <option value="7"><?php echo t('phonelogs.level_all'); ?></option>
                <option value="4"><?php echo t('phonelogs.level_warning'); ?></option>
                <option value="3"><?php echo t('phonelogs.level_error'); ?></option>
            </select>
            <label class="u-check-label-6"><input type="checkbox" id="plLive" class="u-accent" checked> <?php echo t('phonelogs.live'); ?></label>
            <button type="button" class="btn btn-secondary btn-sm" id="plClear"><i class="fas fa-eraser"></i> <?php echo t('phonelogs.clear'); ?></button>
            <span class="text-muted u-fs-11" id="plCount"></span>
        </div>
        <div class="u-h-65vh u-of-auto u-bg-bg-input u-bd-1-solid-border-color u-r-8 u-mono u-fs-12 u-lh-1-5" id="plBox">
            <?php echo uiTableOpen([], ['body_id' => 'plRows']); ?><?php echo uiTableClose(); ?>
            <div class="text-muted u-p-16" id="plEmpty"><?php echo t('phonelogs.empty'); ?></div>
        </div>
    </div>
</div>
</div>
<script src="<?php echo asset('/assets/js/phone_logs.js'); ?>"></script>
<?php endif; ?>
