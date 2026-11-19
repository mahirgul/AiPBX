<?php
/**
 * The variables a data source can use: one help box for every screen that has
 * a source (Dynamic Routing, auto-dialer, caller name). docs/dynamic-routing.md
 * has the same list.
 *
 * @var string $helpId element id of the box
 */
$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$rows = [
    'input' => t('data_source.var_input'),
    'caller_id' => t('data_source.var_caller_id'),
    'caller_name' => t('data_source.var_caller_name'),
    'did' => t('data_source.var_did'),
    'uniqueid' => t('data_source.var_uniqueid'),
    'linkedid' => t('data_source.var_linkedid'),
    'lang' => t('data_source.var_lang'),
    'date' => t('data_source.var_date'),
    'time' => t('data_source.var_time'),
    'var_NAME' => t('data_source.var_channel'),
];
?>
<details class="u-mt-8" id="<?php echo $h($helpId ?? 'dataSourceHelp'); ?>">
    <summary class="u-fs-12 u-primary"><i class="fas fa-circle-question"></i> <?php echo $h(t('data_source.help_title')); ?></summary>
    <div class="u-fs-12 u-mt-8">
        <p class="u-m-0 u-mb-8"><?php echo $h(t('data_source.help_where')); ?></p>
        <table class="table table-sm u-mb-8">
            <tbody>
            <?php foreach ($rows as $name => $label): ?>
                <tr>
                    <td><code>{<?php echo $h($name); ?>}</code> <code>:<?php echo $h($name); ?></code></td>
                    <td><?php echo $h($label); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="u-m-0 u-mb-4"><strong><?php echo $h(t('data_source.help_result')); ?></strong></p>
        <p class="u-m-0 u-muted"><?php echo $h(t('data_source.help_result_body')); ?></p>
        <p class="u-m-0 u-mt-8"><strong><?php echo $h(t('data_source.help_examples')); ?></strong></p>
        <pre class="u-mono u-fs-11 u-m-0">SELECT segment, name FROM customers WHERE phone = :caller_id LIMIT 1
https://crm.example.com/api/lookup?phone={caller_id}&amp;digits={input}
{"phone": "{caller_id}", "account": "{input}"}</pre>
    </div>
</details>
