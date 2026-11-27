<?php
/**
 * Dialplan lines that more than one builder needs: the lowest layer of the dialplan code.
 *
 * DialplanBuilders.php (the entry point of the builders) includes DialplanExtensionBuilder.php, and that file
 * needs jitterBufferLine(): while it lived in DialplanBuilders.php the two included each other. Lines used by
 * several builders belong here, in a file that includes no other builder.
 */

require_once __DIR__ . '/../../config.php';

/**
 * The jitter buffer line before a Dial (PBX settings → Tuning): nothing when
 * switched off, Asterisk's defaults unless a size was changed, and never for
 * a fax (it reshapes the modem's timing).
 */
function jitterBufferLine(): string
{
    if ((string) getSystemSetting('jitterbuffer_enabled', '1') === '0') {
        return '';
    }
    $max = (string) getSystemSetting('jitterbuffer_max_size', '');
    $init = (string) getSystemSetting('jitterbuffer_initial_size', '');
    $spec = (($max !== '' && $max !== '200') || ($init !== '' && $init !== '100'))
        ? max(50, (int) ($max ?: 200)) . ',' . max(20, (int) ($init ?: 100)) : 'default';
    return " same => n,ExecIf(\$[\"\${AIPBX_FAX}\" != \"1\"]?Set(JITTERBUFFER(adaptive)={$spec}))\n";
}
