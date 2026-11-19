<?php
// AGI script: reads what Asterisk sends, works out a value, writes it to AGI_RESULT.
// Runs in a sandbox: no network unless "Network access" is on, writes only to its own folder.

$env = [];
while (($line = fgets(STDIN)) !== false && trim($line) !== '') {
    [$key, $value] = array_pad(explode(': ', trim($line), 2), 2, '');
    $env[$key] = $value;
}

function agi(string $command): string
{
    fwrite(STDOUT, $command . "\n");
    return trim((string) fgets(STDIN));
}

function getVar(string $name): string
{
    $answer = agi('GET VARIABLE ' . $name); // 200 result=1 (value)
    return preg_match('/\((.*)\)$/', $answer, $m) ? $m[1] : '';
}

$caller = $env['agi_callerid'] ?? '';
$firstArg = $env['agi_arg_1'] ?? '';
$digits = getVar('DR_INPUT');

// Your logic here; for example a VIP list.
$result = str_ends_with($caller, '1000') ? 'vip' : 'normal';

agi('SET VARIABLE AGI_RESULT "' . $result . '"');
agi('SET VARIABLE AGI_NAME "Customer ' . $caller . '"');
