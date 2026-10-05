#!/usr/bin/env php
<?php
/**
 * Generates the masters of the Turkish prompt set from sounds/core-sounds-tr.txt
 * with the Google provider configured under AI → Cloud TTS. Maintainer tool:
 * installs never call a cloud service — they unpack the package built from
 * these masters by scripts/build_tr_sounds.sh.
 *
 *   sudo -u www-data php bin/generate_tr_sounds.php --out=DIR [--voice=tr-TR-Wavenet-C] [name ...]
 *
 * Output: DIR/<name>.wav, 16 kHz 16-bit mono (lossless LINEAR16 from Google),
 * silence trimmed, peak -6 dBFS, 150 ms tail. Names given on the command line
 * limit the run to those prompts.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/AiTtsService.php';

$opts = getopt('', ['out:', 'voice:'], $rest);
$repo = dirname(__DIR__, 2);
if (empty($opts['out'])) {
    fwrite(STDERR, "usage: generate_tr_sounds.php --out=DIR [--voice=VOICE] [name ...]\n");
    exit(2);
}
$out = rtrim($opts['out'], '/');
$voice = $opts['voice'] ?? 'tr-TR-Wavenet-C';
$only = array_slice($_SERVER['argv'], $rest);

$prompts = [];
foreach (file("$repo/sounds/core-sounds-tr.txt", FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('#^([A-Za-z0-9_/-]+)\|(.+)$#', $line, $m)) {
        $prompts[$m[1]] = trim($m[2]);
    }
}
if ($only) {
    $unknown = array_diff($only, array_keys($prompts));
    if ($unknown) {
        fwrite(STDERR, 'unknown prompt(s): ' . implode(' ', $unknown) . "\n");
        exit(1);
    }
    $prompts = array_intersect_key($prompts, array_flip($only));
}

$client = AiTtsService::client('google');
if (!$client instanceof GoogleTts) {
    fwrite(STDERR, "the Google provider is required\n");
    exit(1);
}
$tmp = tempnam(sys_get_temp_dir(), 'trtts') . '.wav';
$failed = 0;
foreach ($prompts as $name => $text) {
    $wav = "$out/$name.wav";
    @mkdir(dirname($wav), 0755, true);
    try {
        file_put_contents($tmp, $client->synthesizeWav($text, $voice, 'tr-TR', 1.0, 16000));
    } catch (\Throwable $e) {
        fwrite(STDERR, "$name: " . $e->getMessage() . "\n");
        $failed++;
        continue;
    }
    $log = [];
    $cmd = sprintf('sox %s -r 16000 -c 1 -b 16 %s silence 1 0.02 0.1%% reverse silence 1 0.02 0.1%% reverse gain -n -6 pad 0 0.15 2>&1',
        escapeshellarg($tmp), escapeshellarg($wav));
    exec($cmd, $log, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "$name: sox failed: " . implode(' ', $log) . "\n");
        $failed++;
        continue;
    }
    echo "$name\n";
}
@unlink($tmp);
exit($failed ? 1 : 0);
