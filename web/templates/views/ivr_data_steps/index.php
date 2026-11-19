<?php
/**
 * PBX -> Dynamic Routing (/dynamic-routing)
 *
 * @var list<array<string, mixed>> $steps
 * @var list<array<string, mixed>> $dbs
 * @var array<string, string> $agi_choices
 * @var list<array<string, mixed>> $announcements
 * @var array $dest_modules
 * @var list<string> $vars
 */
use PBX\Destinations\DestinationRegistry;

$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = getCSRFToken();
$destCache = [];
$sourceLabels = [
    'database' => t('dynamic_routing.src_database'), 'http' => t('dynamic_routing.src_http'), 'agi' => t('dynamic_routing.src_agi'),
    'saved_query' => t('external_dbs.tab_queries'),
    'value' => t('dynamic_routing.src_value'), 'none' => t('dynamic_routing.src_none'),
];
$inputLabels = [
    'dtmf' => t('dynamic_routing.in_dtmf'), 'caller_id' => t('dynamic_routing.in_caller_id'),
    'did' => t('dynamic_routing.in_did'), 'variable' => t('dynamic_routing.in_variable'),
];
$destSelect = function (string $prefix) use ($h, $dest_modules): string {
    $html = '<div class="u-d-grid u-gtc-1fr-1fr u-gap-8"><select name="' . $prefix . '_dest_type" id="dr_' . $prefix . '_dest_type" class="form-control" data-dest-target="dr_' . $prefix . '_dest_id">';
    if ($prefix === 'busy') {
        $html .= '<option value="">' . $h(t('dynamic_routing.busy_none')) . '</option>';
    }
    foreach ($dest_modules as $dm) {
        $html .= '<option value="' . $h($dm->getKey()) . '">' . $h($dm->getName()) . '</option>';
    }
    return $html . '</select><select name="' . $prefix . '_dest_id" id="dr_' . $prefix . '_dest_id" class="form-control"></select></div>';
};
$soundOptions = function () use ($h, $announcements): string {
    $html = '<option value="">' . $h(t('dynamic_routing.no_prompt')) . '</option>';
    foreach ($announcements as $a) {
        $html .= '<option value="' . $h($a['audio_file']) . '">' . $h($a['title']) . '</option>';
    }
    return $html;
};
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-route', t('dynamic_routing.title'), 'dynamicRoutingHelpBox'); ?>
    <span class="badge badge-secondary"><?php echo count($steps); ?></span>
    <?php echo uiPageHeaderActions(); ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="drOpen(null)">
            <i class="fas fa-plus-circle"></i> <?php echo $h(t('common.add_new')); ?>
        </button>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('dynamicRoutingHelpBox', t('dynamic_routing.help_title')); ?>
        <p class="u-m-0"><?php echo $h(t('dynamic_routing.help_body')); ?></p>
    <?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        t('common.name'),
        t('dynamic_routing.col_input'),
        t('dynamic_routing.col_source'),
        t('dynamic_routing.col_rules'),
        t('common.status'),
        ['label' => t('common.actions'), 'class' => 'text-right'],
    ]); ?>
        <?php if (empty($steps)): ?>
            <?php echo uiTableEmptyRow(6, t('dynamic_routing.empty'), 'fa-route'); ?>
        <?php else: ?>
            <?php foreach ($steps as $s): ?>
                <tr>
                    <td>
                        <strong><?php echo $h($s['name']); ?></strong>
                        <?php if (!empty($s['description'])): ?>
                            <div class="u-muted u-fs-11"><?php echo $h($s['description']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-secondary"><?php echo $h($inputLabels[$s['input_source']] ?? $s['input_source']); ?></span></td>
                    <td><span class="badge badge-info"><?php echo $h($sourceLabels[$s['data_source']] ?? $s['data_source']); ?></span></td>
                    <td>
                        <?php foreach (array_slice($s['rules'], 0, 3) as $r): ?>
                            <span class="badge <?php echo $h(DestinationRegistry::badgeClassFor($r['dest_type'])); ?>">
                                <?php echo $h($r['type'] === 'range' ? $r['value'] . '–' . $r['value2'] : ($r['type'] === 'prefix' ? $r['value'] . '…' : $r['value'])); ?>
                                → <?php echo $h(DestinationRegistry::resolveLabel($r['dest_type'], $r['dest_id'], $destCache) ?? $r['dest_type']); ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if (count($s['rules']) > 3): ?>
                            <span class="u-muted u-fs-11">+<?php echo count($s['rules']) - 3; ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo uiStatusToggleForm($s['id'], $s['is_active'], 'id'); ?></td>
                    <td class="text-right">
                        <?php echo uiRowActions($s, 'drOpen', 'id', 'delete_step', t('ivr_data.confirm_delete')); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php echo uiTableClose(); ?>
</div>

<div class="modal-overlay" id="drModal">
    <div class="modal-card u-maxw-900">
        <div class="modal-header">
            <h3 class="u-title" id="drModalTitle"><i class="fas fa-route u-primary"></i> <?php echo $h(t('dynamic_routing.new_title')); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="drClose()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off" id="drForm">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="save_step" value="1">
                <input type="hidden" name="id" id="dr_id" value="">

                <div class="u-d-grid u-gtc-2fr-1fr u-gap-12">
                    <div class="form-group">
                        <label class="form-label" for="dr_name"><?php echo $h(t('common.name')); ?> *</label>
                        <input type="text" name="name" id="dr_name" class="form-control" required maxlength="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="dr_channel_var"><?php echo $h(t('dynamic_routing.result_var')); ?></label>
                        <input type="text" name="channel_var_name" id="dr_channel_var" class="form-control u-mono" maxlength="64" pattern="[A-Za-z0-9_]+">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="dr_description"><?php echo $h(t('common.description')); ?></label>
                    <input type="text" name="description" id="dr_description" class="form-control" maxlength="255">
                </div>

                <h4 class="u-fw-700 u-mt-16 u-mb-8"><i class="fas fa-keyboard u-primary"></i> 1. <?php echo $h(t('dynamic_routing.section_input')); ?></h4>
                <div class="u-d-grid u-gtc-1fr-1fr u-gap-12">
                    <div class="form-group">
                        <label class="form-label" for="dr_input_source"><?php echo $h(t('dynamic_routing.input')); ?></label>
                        <select name="input_source" id="dr_input_source" class="form-control" onchange="drToggle()">
                            <?php foreach ($inputLabels as $k => $label): ?>
                                <option value="<?php echo $h($k); ?>"><?php echo $h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" data-dr-input="variable">
                        <label class="form-label" for="dr_input_var"><?php echo $h(t('dynamic_routing.input_var')); ?></label>
                        <input type="text" name="input_var" id="dr_input_var" class="form-control u-mono" maxlength="64" placeholder="DR_VALUE">
                        <small class="u-hint"><?php echo $h(t('dynamic_routing.input_var_hint')); ?></small>
                    </div>
                </div>
                <div data-dr-input="dtmf">
                    <div class="u-d-grid u-gtc-1fr-1fr u-gap-12">
                        <div class="form-group">
                            <label class="form-label" for="dr_prompt"><?php echo $h(t('dynamic_routing.prompt')); ?></label>
                            <select name="prompt_file" id="dr_prompt" class="form-control"><?php echo $soundOptions(); ?></select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="dr_invalid_prompt"><?php echo $h(t('dynamic_routing.invalid_prompt')); ?></label>
                            <select name="invalid_prompt" id="dr_invalid_prompt" class="form-control"><?php echo $soundOptions(); ?></select>
                        </div>
                    </div>
                    <div class="u-d-grid u-gtc-repeat-4-1fr u-gap-12">
                        <div class="form-group">
                            <label class="form-label" for="dr_min_digits"><?php echo $h(t('dynamic_routing.min_digits')); ?></label>
                            <input type="number" name="min_digits" id="dr_min_digits" class="form-control" min="1" max="30">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="dr_max_digits"><?php echo $h(t('dynamic_routing.max_digits')); ?></label>
                            <input type="number" name="max_digits" id="dr_max_digits" class="form-control" min="1" max="30">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="dr_timeout"><?php echo $h(t('dynamic_routing.input_timeout')); ?></label>
                            <input type="number" name="input_timeout_sec" id="dr_timeout" class="form-control" min="1" max="30">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="dr_retries"><?php echo $h(t('dynamic_routing.retries')); ?></label>
                            <input type="number" name="retries" id="dr_retries" class="form-control" min="0" max="5">
                        </div>
                    </div>
                </div>

                <h4 class="u-fw-700 u-mt-16 u-mb-8"><i class="fas fa-database u-primary"></i> 2. <?php echo $h(t('dynamic_routing.section_source')); ?></h4>
                <div class="form-group">
                    <label class="form-label" for="dr_source"><?php echo $h(t('dynamic_routing.source')); ?></label>
                    <select name="data_source" id="dr_source" class="form-control" onchange="drToggle()">
                        <?php foreach ($sourceLabels as $k => $label): ?>
                            <option value="<?php echo $h($k); ?>"><?php echo $h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div data-dr-source="database">
                    <div class="form-group">
                        <label class="form-label" for="dr_db"><?php echo $h(t('dynamic_routing.db')); ?></label>
                        <select name="external_db_id" id="dr_db" class="form-control">
                            <option value=""><?php echo $h(t('dynamic_routing.db_pick')); ?></option>
                            <?php foreach ($dbs as $d): ?>
                                <option value="<?php echo (int) $d['id']; ?>"><?php echo $h($d['name'] . ' (' . $d['driver'] . ': ' . $d['database_name'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$dbs): ?><small class="u-hint"><a href="/external-dbs"><?php echo $h(t('dynamic_routing.db_none')); ?></a></small><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="dr_sql"><?php echo $h(t('dynamic_routing.sql')); ?></label>
                        <textarea name="query_sql" id="dr_sql" class="form-control u-mono" rows="3" placeholder="SELECT segment, name FROM customers WHERE phone = :caller_id LIMIT 1"></textarea>
                        <small class="u-hint"><?php echo $h(t('dynamic_routing.sql_hint')); ?></small>
                    </div>
                </div>
                <div class="form-group" data-dr-source="saved_query">
                    <label class="form-label" for="dr_saved_query"><?php echo $h(t('external_dbs.query_key')); ?></label>
                    <select id="dr_saved_query" name="saved_query_id" class="form-control">
                        <option value=""><?php echo $h(t('external_dbs.query_select_public')); ?></option>
                        <?php foreach ($saved_queries ?? [] as $query): ?>
                            <option value="<?php echo (int) $query['id']; ?>"><?php echo $h($query['query_key'] . ' — ' . $query['description']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="u-hint"><a href="/external-dbs?tab=queries"><?php echo $h(t('external_dbs.tab_queries')); ?></a></small>
                </div>
                <div data-dr-source="http">
                    <div class="u-d-grid u-gtc-2fr-1fr u-gap-12">
                        <div class="form-group">
                            <label class="form-label" for="dr_url">URL</label>
                            <input type="text" name="http_url" id="dr_url" class="form-control u-mono" maxlength="500" placeholder="https://crm.example.com/api/lookup?phone={caller_id}">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="dr_method"><?php echo $h(t('dynamic_routing.method')); ?></label>
                            <select name="http_method" id="dr_method" class="form-control" onchange="drToggle()">
                                <option value="GET">GET</option>
                                <option value="POST">POST (JSON)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" data-dr-method="POST">
                        <label class="form-label" for="dr_body"><?php echo $h(t('dynamic_routing.body')); ?></label>
                        <textarea name="http_body" id="dr_body" class="form-control u-mono" rows="2" placeholder='{"phone": "{caller_id}", "digits": "{input}"}'></textarea>
                        <small class="u-hint"><?php echo $h(t('dynamic_routing.body_hint')); ?></small>
                    </div>
                    <div class="u-d-grid u-gtc-1fr-1fr u-gap-12">
                        <div class="form-group">
                            <label class="form-label" for="dr_headers"><?php echo $h(t('dynamic_routing.headers')); ?></label>
                            <textarea name="http_headers" id="dr_headers" class="form-control u-mono" rows="2" placeholder="Authorization: Bearer …"></textarea>
                            <small class="u-hint"><?php echo $h(t('dynamic_routing.headers_hint')); ?></small>
                            <label class="u-check-label u-fs-12 u-mt-4" id="dr_headers_clear_box">
                                <input type="checkbox" name="http_headers_clear" value="1" class="u-check"> <?php echo $h(t('dynamic_routing.headers_clear')); ?>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="dr_json_path"><?php echo $h(t('dynamic_routing.json_path')); ?></label>
                            <input type="text" name="json_path" id="dr_json_path" class="form-control u-mono" maxlength="200" placeholder="data.customer.segment">
                            <small class="u-hint"><?php echo $h(t('dynamic_routing.json_path_hint')); ?></small>
                        </div>
                    </div>
                </div>
                <div data-dr-source="agi" class="form-group">
                    <label class="form-label" for="dr_agi"><?php echo $h(t('dynamic_routing.agi')); ?></label>
                    <select name="agi_script" id="dr_agi" class="form-control">
                        <option value=""><?php echo $h(t('dynamic_routing.agi_pick')); ?></option>
                        <?php foreach ($agi_choices as $k => $label): ?>
                            <option value="<?php echo $h($k); ?>"><?php echo $h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="u-hint"><?php echo $h(t('dynamic_routing.agi_hint')); ?> <a href="/agi-scripts"><?php echo $h(t('agi_scripts.title')); ?></a></small>
                </div>
                <div data-dr-source="value" class="form-group">
                    <label class="form-label" for="dr_value"><?php echo $h(t('dynamic_routing.value')); ?></label>
                    <input type="text" name="value_template" id="dr_value" class="form-control u-mono" maxlength="255" placeholder="{var_CUSTOMER_TYPE}">
                    <small class="u-hint"><?php echo $h(t('dynamic_routing.value_hint')); ?></small>
                </div>
                <div data-dr-source="database http" class="form-group">
                    <label class="form-label" for="dr_prefix"><?php echo $h(t('dynamic_routing.var_prefix')); ?></label>
                    <input type="text" name="var_prefix" id="dr_prefix" class="form-control u-mono u-maxw-240" maxlength="32" placeholder="DR_">
                    <small class="u-hint"><?php echo $h(t('dynamic_routing.var_prefix_hint')); ?></small>
                </div>
                <?php $helpId = 'drVarsHelp'; require dirname(__DIR__, 2) . '/data_source_help.php'; ?>

                <div class="u-bg-bg-body u-p-12 u-mt-12" id="drTestBox">
                    <div class="u-fw-700 u-mb-8"><i class="fas fa-vial u-primary"></i> <?php echo $h(t('dynamic_routing.test')); ?></div>
                    <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-150-1fr u-gap-8">
                        <input type="text" class="form-control" data-dr-sample="input" placeholder="input">
                        <input type="text" class="form-control" data-dr-sample="caller_id" placeholder="caller_id">
                        <input type="text" class="form-control" data-dr-sample="did" placeholder="did">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="drTest()"><i class="fas fa-play"></i> <?php echo $h(t('dynamic_routing.test_run')); ?></button>
                    </div>
                    <pre class="u-mono u-fs-11 u-mt-8 u-m-0" id="drTestOut" hidden></pre>
                </div>

                <h4 class="u-fw-700 u-mt-16 u-mb-8"><i class="fas fa-code-branch u-primary"></i> 3. <?php echo $h(t('dynamic_routing.section_rules')); ?></h4>
                <p class="u-hint u-m-0 u-mb-8"><?php echo $h(t('dynamic_routing.rules_hint')); ?></p>
                <div id="drRules"></div>
                <button type="button" class="btn btn-secondary btn-sm u-mb-12" onclick="drAddRule()"><i class="fas fa-plus"></i> <?php echo $h(t('dynamic_routing.rule_add')); ?></button>

                <div class="u-d-grid u-gtc-1fr-1fr u-gap-12">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-check u-success"></i> <?php echo $h(t('dynamic_routing.dest_found')); ?></label>
                        <?php echo $destSelect('found'); ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-times u-warning"></i> <?php echo $h(t('dynamic_routing.dest_not_found')); ?></label>
                        <?php echo $destSelect('not_found'); ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-clock u-danger"></i> <?php echo $h(t('dynamic_routing.dest_timeout')); ?></label>
                        <?php echo $destSelect('timeout'); ?>
                    </div>
                </div>

                <h4 class="u-fw-700 u-mt-16 u-mb-8"><i class="fas fa-sliders u-primary"></i> 4. <?php echo $h(t('dynamic_routing.section_more')); ?></h4>
                <div class="u-d-grid u-gtc-1fr-2fr u-gap-12">
                    <div class="form-group">
                        <label class="form-label" for="dr_name_mode"><?php echo $h(t('dynamic_routing.caller_name')); ?></label>
                        <select name="caller_name_mode" id="dr_name_mode" class="form-control" onchange="drToggle()">
                            <option value=""><?php echo $h(t('dynamic_routing.caller_name_keep')); ?></option>
                            <option value="prefix"><?php echo $h(t('dynamic_routing.caller_name_prefix')); ?></option>
                            <option value="replace"><?php echo $h(t('dynamic_routing.caller_name_replace')); ?></option>
                        </select>
                    </div>
                    <div class="form-group" data-dr-name="prefix replace">
                        <label class="form-label" for="dr_name_tpl"><?php echo $h(t('dynamic_routing.caller_name_tpl')); ?></label>
                        <input type="text" name="caller_name_template" id="dr_name_tpl" class="form-control" maxlength="100" placeholder="VIP – {NAME}">
                        <small class="u-hint"><?php echo $h(t('dynamic_routing.caller_name_hint')); ?></small>
                    </div>
                </div>
                <div class="form-group">
                    <label class="u-check-label">
                        <input type="checkbox" name="direct_extension" id="dr_direct" value="1" class="u-check" onchange="drToggle()">
                        <span><?php echo $h(t('dynamic_routing.direct_extension')); ?></span>
                    </label>
                </div>
                <div class="form-group" data-dr-direct="1">
                    <label class="form-label"><?php echo $h(t('dynamic_routing.dest_busy')); ?></label>
                    <?php echo $destSelect('busy'); ?>
                </div>
                <div class="form-group">
                    <label class="u-check-label">
                        <input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" id="dr_active" value="1" class="u-check" checked>
                        <span><?php echo $h(t('common.active')); ?></span>
                    </label>
                </div>
                <?php echo uiModalFooter('drClose()'); ?>
            </form>
        </div>
    </div>
</div>

<template id="drRuleTpl">
    <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-150-1fr u-gap-8 u-mb-8" data-dr-rule>
        <select class="form-control" data-f="type" onchange="drRuleToggle(this)">
            <option value="exact"><?php echo $h(t('dynamic_routing.rule_exact')); ?></option>
            <option value="prefix"><?php echo $h(t('dynamic_routing.rule_prefix')); ?></option>
            <option value="range"><?php echo $h(t('dynamic_routing.rule_range')); ?></option>
        </select>
        <input type="text" class="form-control" data-f="value" maxlength="64" placeholder="<?php echo $h(t('dynamic_routing.rule_value')); ?>">
        <input type="text" class="form-control" data-f="value2" maxlength="22" placeholder="<?php echo $h(t('dynamic_routing.rule_to')); ?>">
        <select class="form-control" data-f="dest_type">
            <?php foreach ($dest_modules as $dm): ?>
                <option value="<?php echo $h($dm->getKey()); ?>"><?php echo $h($dm->getName()); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-control" data-f="dest_id"></select>
        <button type="button" class="btn btn-icon btn-danger btn-sm" onclick="this.closest('[data-dr-rule]').remove()" title="<?php echo $h(t('common.delete')); ?>"><i class="fas fa-trash-alt"></i></button>
    </div>
</template>

<script src="<?php echo asset('/assets/js/destinations_helper.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/dynamic_routing.js'); ?>"></script>
