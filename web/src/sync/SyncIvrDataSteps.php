<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/DataSourceService.php';
require_once dirname(__DIR__) . '/services/AgiScriptService.php';
require_once dirname(__DIR__) . '/services/IvrDataStepService.php';

/**
 * Dynamic Routing (formerly IVR data steps): /etc/asterisk/pbx/extensions_ivrdata.conf
 *
 * Each step: take an input (keys, caller number, DID or a channel variable),
 * ask a source (external database, URL, AGI script, a value, or none), then go
 * where the first matching rule says; otherwise to the found / not found /
 * error destinations. Database and URL sources are asked through the loopback
 * api/ivr_data_lookup.php, which answers "status|value|NAME=value|...".
 */

/** A template in dialplan form: {caller_id} -> ${CALLERID(num)}; other text kept to safe characters. */
function dynamicRoutingExpr(string $template, string $prefix = 'DR_'): string
{
    $parts = preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    $out = '';
    foreach ($parts as $p) {
        if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $p, $m)) {
            $n = $m[1];
            if ($n === 'value') {
                $out .= '${DR_VALUE}';
            } elseif ($n === 'input') {
                $out .= '${DR_INPUT}';
            } elseif (isset(DataSourceService::DIALPLAN_VALUES[$n])) {
                $out .= DataSourceService::DIALPLAN_VALUES[$n];
            } elseif (str_starts_with($n, 'var_') && DataSourceService::isVar($n)) {
                $out .= '${' . substr($n, 4) . '}';
            } elseif (preg_match('/^[A-Z][A-Z0-9_]{0,31}$/', $n)) {
                // A result field of the source: {NAME} -> ${<prefix>NAME}
                $out .= '${' . $prefix . $n . '}';
            }
            continue;
        }
        $out .= preg_replace('/[^\p{L}\p{N}\p{Pd} _.+@:\/#*-]/u', '', $p) ?? '';
    }
    return $out;
}

/** The GotoIf condition of one rule, or '' for a rule that cannot match. */
function dynamicRoutingCondition(array $rule): string
{
    $v = (string) ($rule['value'] ?? '');
    switch ($rule['type'] ?? '') {
        case 'exact':
            return '$["${DR_VALUE}" = "' . $v . '"]';
        case 'prefix':
            return '$["${DR_VALUE:0:' . mb_strlen($v) . '}" = "' . $v . '"]';
        case 'range':
            $to = (string) ($rule['value2'] ?? '');
            return '$["${DR_NUM}" != "nan" & ${DR_NUM} >= ' . $v . ' & ${DR_NUM} <= ' . $to . ']';
    }
    return '';
}

function buildIvrDataStepDialplanBlock(array $step): string
{
    $id = (int) $step['id'];
    $name = dialplanText((string) $step['name']);
    $prompt = !empty($step['prompt_file']) ? FileHelper::sanitizeAudioPath((string) $step['prompt_file']) : '';
    $invalid = !empty($step['invalid_prompt']) ? FileHelper::sanitizeAudioPath((string) $step['invalid_prompt']) : '';
    $input = (string) ($step['input_source'] ?? 'dtmf');
    $maxDigits = max(1, min(30, (int) ($step['max_digits'] ?? 10)));
    $minDigits = max(1, min($maxDigits, (int) ($step['min_digits'] ?? 1)));
    $timeout = max(1, min(30, (int) ($step['input_timeout_sec'] ?? 5)));
    $retries = $input === 'dtmf' ? max(0, min(5, (int) ($step['retries'] ?? 0))) : 0;
    $channelVar = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($step['channel_var_name'] ?? 'IVR_DATA_VAL')) ?: 'IVR_DATA_VAL';
    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', strtoupper((string) ($step['var_prefix'] ?? ''))) ?: 'DR_';
    $source = (string) ($step['data_source'] ?? 'database');
    $notFound = $retries > 0 ? 'invalid' : 'not_found';

    $c = "; Dynamic Routing {$id}: {$name}\n[aipbx-ivr-data-{$id}]\n";
    $c .= "exten => s,1,NoOp(Dynamic Routing {$id}: {$name})\n";
    $c .= " same => n,Set(DR_TRY=0)\n";

    // 1. Input
    switch ($input) {
        case 'caller_id':
            $c .= " same => n,Set(DR_INPUT=\${CALLERID(num)})\n";
            break;
        case 'did':
            $c .= " same => n,Set(DR_INPUT=\${NORMALIZED_DID})\n";
            break;
        case 'variable':
            $var = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($step['input_var'] ?? '')) ?: 'DR_VALUE';
            $c .= " same => n,Set(DR_INPUT=\${{$var}})\n";
            break;
        default:
            $c .= " same => n,Answer()\n";
            $c .= " same => n(ask),Read(DR_INPUT,{$prompt},{$maxDigits},,,{$timeout})\n";
            $c .= " same => n,GotoIf(\$[\${LEN(\${DR_INPUT})} < {$minDigits}]?" . ($retries > 0 ? 'invalid' : 'timeout') . ")\n";
    }
    $c .= " same => n,Set(IVR_INPUT=\${DR_INPUT})\n";

    // 2. Source -> DR_VALUE (and DR_STATUS for database and URL)
    switch ($source) {
        case 'none':
            $c .= " same => n,Set(DR_VALUE=\${DR_INPUT})\n";
            break;
        case 'value':
            $c .= ' same => n,Set(DR_VALUE=' . dynamicRoutingExpr((string) ($step['value_template'] ?? ''), $prefix) . ")\n";
            break;
        case 'agi':
            $target = AgiScriptService::dialplanTarget((string) ($step['agi_script'] ?? ''));
            if ($target === '') {
                $c .= " same => n,Goto(timeout)\n";
                break;
            }
            $c .= " same => n,Set(AGI_RESULT=)\n";
            $c .= " same => n,AGI({$target},\${DR_INPUT})\n";
            $c .= " same => n,GotoIf(\$[\"\${AGISTATUS}\" != \"SUCCESS\"]?timeout)\n";
            $c .= " same => n,Set(DR_VALUE=\${AGI_RESULT})\n";
            break;
        default:
            // The variables the query, URL or body uses; input and caller number always (a POST
            // without a body template sends them, as the IVR data step always did).
            $tpl = $source === 'saved_query' ? IvrDataStepService::sourceOf($step)['sql'] :
                (string) ($step['query_sql'] ?? '') . ' ' . (string) ($step['http_url'] ?? '') . ' ' . (string) ($step['http_body'] ?? '');
            $names = array_unique(['input', 'caller_id', ...DataSourceService::placeholders($tpl, true), ...DataSourceService::placeholders($tpl)]);
            $q = 'step_id=' . $id;
            foreach ($names as $n) {
                if ($n === 'date' || $n === 'time') {
                    continue;
                }
                $expr = $n === 'input' ? '${DR_INPUT}' : (DataSourceService::DIALPLAN_VALUES[$n] ?? '${' . substr($n, 4) . '}');
                $q .= '&' . $n . '=${URIENCODE(' . $expr . ')}';
            }
            $c .= " same => n,Set(CURLOPT(httptimeout)=3)\n";
            $c .= " same => n,Set(DR_RAW=\${CURL(http://127.0.0.1/api/ivr_data_lookup.php?{$q})})\n";
            $c .= " same => n,Set(DR_STATUS=\${CUT(DR_RAW,|,1)})\n";
            $c .= " same => n,Set(DR_VALUE=\${CUT(DR_RAW,|,2)})\n";
            // Extra fields: DR_RAW = status|value|NAME=value|...
            $c .= " same => n,Set(DR_I=3)\n";
            $c .= " same => n,While(\$[\"\${CUT(DR_RAW,|,\${DR_I})}\" != \"\"])\n";
            $c .= " same => n,Set(DR_KV=\${CUT(DR_RAW,|,\${DR_I})})\n";
            $c .= " same => n,Set({$prefix}\${CUT(DR_KV,=,1)}=\${CUT(DR_KV,=,2-)})\n";
            $c .= " same => n,Set(DR_I=\$[\${DR_I} + 1])\n";
            $c .= " same => n,EndWhile()\n";
            $c .= " same => n,GotoIf(\$[\"\${DR_STATUS}\" = \"not_found\"]?{$notFound})\n";
            $c .= " same => n,GotoIf(\$[\"\${DR_STATUS}\" != \"found\"]?timeout)\n";
    }
    $c .= " same => n,GotoIf(\$[\"\${DR_VALUE}\" = \"\"]?{$notFound})\n";
    $c .= " same => n,Set({$channelVar}=\${DR_VALUE})\n";

    // 3. Caller name
    $nameTpl = trim((string) ($step['caller_name_template'] ?? ''));
    $mode = (string) ($step['caller_name_mode'] ?? '');
    if ($nameTpl !== '' && in_array($mode, ['prefix', 'replace'], true)) {
        $expr = dynamicRoutingExpr($nameTpl, $prefix);
        $c .= ' same => n,Set(CALLERID(name)=' . ($mode === 'prefix' ? $expr . ' ${CALLERID(name)}' : $expr) . ")\n";
    }

    // 4. Rules, in order
    $rules = IvrDataStepService::decodeRules((string) ($step['rules_json'] ?? ''));
    if (array_filter($rules, fn ($r) => $r['type'] === 'range')) {
        $c .= " same => n,Set(DR_NUM=\${IF(\$[\${REGEX(\"^-?[0-9]+([.][0-9]+)?\$\" \${DR_VALUE})}]?\${DR_VALUE}:nan)})\n";
    }
    foreach ($rules as $i => $r) {
        $cond = dynamicRoutingCondition($r);
        if ($cond !== '') {
            $c .= " same => n,GotoIf({$cond}?rule{$i})\n";
        }
    }

    // 5. A value that is an extension: ring it; the busy destination when it does not answer.
    if (!empty($step['direct_extension'])) {
        $c .= " same => n,GotoIf(\$[\${REGEX(\"^[0-9]{2,8}\$\" \${DR_VALUE})} = 0]?found)\n";
        $c .= " same => n,GotoIf(\$[\${DIALPLAN_EXISTS(from-internal-pbx-ortak,\${DR_VALUE},1)} = 0]?found)\n";
        $c .= " same => n,Dial(Local/\${DR_VALUE}@from-internal-pbx-ortak/n,,tT)\n";
        $c .= " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"ANSWER\"]?done)\n";
        $c .= ($step['busy_dest_type'] ?? '') !== ''
            ? buildDestinationLines((string) $step['busy_dest_type'], (string) ($step['busy_dest_id'] ?? '')) . "\n"
            : " same => n,Goto(found)\n";
        $c .= " same => n(done),Hangup()\n";
    }

    $c .= " same => n(found),NoOp(Dynamic Routing {$id}: no rule for \${DR_VALUE})\n";
    $c .= buildDestinationLines((string) ($step['found_dest_type'] ?? 'hangup'), (string) ($step['found_dest_id'] ?? '')) . "\n";
    $c .= " same => n,Hangup()\n";

    foreach ($rules as $i => $r) {
        $c .= " same => n(rule{$i}),NoOp(Dynamic Routing {$id}: rule " . ($i + 1) . ")\n";
        $c .= buildDestinationLines($r['dest_type'], $r['dest_id']) . "\n";
        $c .= " same => n,Hangup()\n";
    }

    if ($retries > 0) {
        $c .= " same => n(invalid),Set(DR_TRY=\$[\${DR_TRY} + 1])\n";
        $c .= " same => n,GotoIf(\$[\${DR_TRY} > {$retries}]?not_found)\n";
        if ($invalid !== '') {
            $c .= " same => n,Playback({$invalid})\n";
        }
        $c .= " same => n,Goto(ask)\n";
    }

    $c .= " same => n(not_found),NoOp(Dynamic Routing {$id}: not found)\n";
    $c .= buildDestinationLines((string) ($step['not_found_dest_type'] ?? 'hangup'), (string) ($step['not_found_dest_id'] ?? '')) . "\n";
    $c .= " same => n,Hangup()\n";
    $c .= " same => n(timeout),NoOp(Dynamic Routing {$id}: no answer or error)\n";
    $c .= buildDestinationLines((string) ($step['timeout_dest_type'] ?? 'hangup'), (string) ($step['timeout_dest_id'] ?? '')) . "\n";
    $c .= " same => n,Hangup()\n\n";
    return $c;
}

function syncIvrDataSteps(): bool
{
    return withSyncLock('ivr_data_steps', '__syncIvrDataStepsBody');
}

function __syncIvrDataStepsBody(): bool
{
    $db = getDB();
    $steps = $db->query('SELECT * FROM pbx_ivr_data_steps WHERE is_active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

    $conf = "; ==========================================================\n";
    $conf .= "; Dynamic Routing (Auto-generated by AI PBX)\n";
    $conf .= "; ==========================================================\n\n";

    foreach ($steps as $s) {
        $conf .= buildIvrDataStepDialplanBlock($s);
    }

    return writeConfWithRollback('extensions_ivrdata.conf', $conf, function () {
        AsteriskHelper::assertReloadsOk([AsteriskHelper::reloadDialplan()], t('sync.ctx_ivr_data'));
    }, t('sync.ctx_ivr_data'));
}
