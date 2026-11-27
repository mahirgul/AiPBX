<?php

/**
 * Reads one call (all CDR legs sharing a linkedid) and says, in report terms,
 * who called whom over which trunks.
 *
 * The CDR has no "trunk" field; it is derived from channel names. A PJSIP
 * endpoint is an extension when it is "<digits>" or "<digits>-<suffix>"
 * (3002, 3002-sip, 3002-webrtc, 3002-mob-webrtc) and a trunk otherwise. Known
 * trunks win; unknown non-extension endpoints are still trunks, so calls over
 * a trunk that was renamed or deleted keep showing it.
 */
final class CdrCallAnalyzer
{
    public const INBOUND = 'inbound';
    public const OUTBOUND = 'outbound';
    public const INTERNAL = 'internal';
    public const TRANSIT = 'transit';

    /** MariaDB REGEXP for a channel that belongs to an extension (kept in sync with endpoint()). */
    public const SQL_EXT_CHANNEL = '^PJSIP/[0-9]+(-[a-z]+)*-[0-9a-f]+$';

    /**
     * A CDR row (alias c) that a person answered. A call is answered when it
     * enters a queue (so the caller hears music and announcements), which makes
     * the queue row ANSWERED even when no agent took it: that row has no
     * destination channel and does not count.
     */
    public const SQL_ANSWERED_LEG = "(c.disposition = 'ANSWERED' AND NOT (c.lastapp = 'Queue' AND COALESCE(c.dstchannel, '') = ''))";

    /**
     * @param array<string, string> $trunkTitles trunk_name => title
     * @param array<string, string> $userNames   extension => full name
     * @param array<string, array{title: string, number: string}> $aiApps app_id => {title, number}
     */
    public function __construct(private array $trunkTitles = [], private array $userNames = [], private array $aiApps = [])
    {
    }

    /**
     * What a channel name points at.
     * @return array{kind: string, id: string, device: string} kind: ext | trunk | number | ''
     */
    public function endpoint(?string $channel): array
    {
        $ch = (string) $channel;
        if (preg_match('#^Local/([^@]+)@#', $ch, $m)) {
            // Queue members and follow-me legs: Local/3002@from-internal-pbx-…;1
            return ['kind' => ctype_digit($m[1]) ? 'ext' : 'number', 'id' => $m[1], 'device' => ''];
        }
        if (!preg_match('#^(?:PJSIP|SIP|IAX2)/(.+)-[0-9a-f]+$#i', $ch, $m)) {
            return ['kind' => '', 'id' => '', 'device' => ''];
        }
        $ep = $m[1];
        if (isset($this->trunkTitles[$ep])) {
            return ['kind' => 'trunk', 'id' => $ep, 'device' => ''];
        }
        if (preg_match('/^(\d+)(?:-([a-z]+(?:-[a-z]+)*))?$/i', $ep, $e)) {
            $suffix = strtolower($e[2] ?? '');
            $device = str_contains($suffix, 'mob') ? 'mobil' : (str_contains($suffix, 'webrtc') ? 'webrtc' : ($suffix === 'sip' ? 'sip' : ''));
            return ['kind' => 'ext', 'id' => $e[1], 'device' => $device];
        }
        return ['kind' => 'trunk', 'id' => $ep, 'device' => ''];
    }

    public function trunkTitle(string $name): string
    {
        return $this->trunkTitles[$name] ?? $name;
    }

    public static function isInternalMediaChannel(string $channel): bool
    {
        return str_starts_with($channel, 'AudioSocket/') || str_starts_with($channel, 'UnicastRTP/');
    }

    /** @param array<string, mixed> $leg */
    public static function aiAppIdFromLeg(array $leg): string
    {
        if (self::isInternalMediaChannel((string) ($leg['channel'] ?? ''))) {
            return '';
        }
        $app = (string) ($leg['lastapp'] ?? '');
        if (!in_array($app, ['Stasis', 'AudioSocket'], true)) {
            return '';
        }
        $dcontext = (string) ($leg['dcontext'] ?? '');
        if (preg_match('/^aipbx-ai-app-(\d+)$/', $dcontext, $m)) {
            return $m[1];
        }
        $lastdata = (string) ($leg['lastdata'] ?? '');
        if ($app === 'Stasis' && preg_match('/^aipbx-ai,(\d+),/', $lastdata, $m)) {
            return $m[1];
        }
        return '';
    }

    public function aiTitle(string $id): string
    {
        $title = trim((string) ($this->aiApps[$id]['title'] ?? ''));
        return $title !== '' ? $title : '#' . $id;
    }

    /**
     * @param list<array<string, mixed>> $legs CDR rows of one call
     * @return array<string, mixed>
     */
    public function analyze(array $legs): array
    {
        // CDR rows are written when a leg ends, so ids are not chronological:
        // queue member (Local/) legs often get a smaller id than the leg that
        // started the call, and ARI AudioSocket media legs carry no caller info.
        $rank = static fn(array $l): int => self::isInternalMediaChannel((string) ($l['channel'] ?? ''))
            ? 2
            : (str_starts_with((string) ($l['channel'] ?? ''), 'Local/') ? 1 : 0);
        usort($legs, static fn($a, $b) => [
            $rank($a) === 2 ? 1 : 0,
            (string) ($a['calldate'] ?? ''),
            $rank($a),
            (int) $a['id'],
        ] <=> [
            $rank($b) === 2 ? 1 : 0,
            (string) ($b['calldate'] ?? ''),
            $rank($b),
            (int) $b['id'],
        ]);
        $realLegs = array_values(array_filter($legs, static fn($l) => !self::isInternalMediaChannel((string) ($l['channel'] ?? ''))));
        $first = $realLegs[0] ?? ($legs[0] ?? []);
        $src = trim((string) ($first['src'] ?? ''));

        $in = $this->endpoint($first['channel'] ?? '');
        $inTrunk = $in['kind'] === 'trunk' ? $in['id'] : '';

        $callerName = '';
        if (preg_match('/^"([^"]*)"/', (string) ($first['clid'] ?? ''), $m) && trim($m[1]) !== '' && trim($m[1]) !== $src) {
            $callerName = trim($m[1]);
        } elseif (isset($this->userNames[$src])) {
            $callerName = $this->userNames[$src];
        }

        // Every Dial/Queue attempt and what it reached, plus AI agent sessions.
        $steps = [];
        foreach ($legs as $leg) {
            $aiId = self::aiAppIdFromLeg($leg);
            if ($aiId !== '') {
                $steps[] = [
                    'kind' => 'ai',
                    'id' => $aiId,
                    'number' => (string) ($this->aiApps[$aiId]['number'] ?? ''),
                    'device' => '',
                    'answered' => ($leg['disposition'] ?? '') === 'ANSWERED',
                ];
                continue;
            }
            if (!in_array($leg['lastapp'] ?? '', ['Dial', 'Queue'], true)) {
                continue;
            }
            $t = $this->endpoint($leg['dstchannel'] ?? '');
            if ($t['kind'] === '') {
                continue;
            }
            $number = $t['id'];
            if ($t['kind'] === 'trunk') {
                // The number actually sent to the trunk (after prefix rules) is in Dial()'s argument.
                $number = preg_match('#^(?:PJSIP|SIP|IAX2)/([^@/,&]+)@#', (string) ($leg['lastdata'] ?? ''), $d) ? $d[1] : (string) ($leg['dst'] ?? '');
            }
            $steps[] = [
                'kind' => $t['kind'],
                'id' => $t['id'],
                'number' => $number,
                'device' => $t['device'],
                'answered' => ($leg['disposition'] ?? '') === 'ANSWERED',
            ];
        }

        $answered = array_values(array_filter($steps, fn($s) => $s['answered']));
        $trunkSteps = array_values(array_filter($steps, fn($s) => $s['kind'] === 'trunk'));
        $answeredTrunks = array_values(array_filter($answered, fn($s) => $s['kind'] === 'trunk'));
        $answeredExts = array_values(array_filter($answered, fn($s) => $s['kind'] === 'ext'));
        $answeredAi = array_values(array_filter($answered, fn($s) => $s['kind'] === 'ai'));
        $aiAnswer = $answeredAi[0] ?? null;

        $out = $answeredTrunks ? end($answeredTrunks) : ($trunkSteps ? end($trunkSteps) : null);
        $extAnswer = $answeredExts[0] ?? null;
        if ($extAnswer && $extAnswer['device'] === '') {
            // A queue answer is a Local/ leg; the device is on that extension's own leg.
            foreach ($answeredExts as $s) {
                if ($s['id'] === $extAnswer['id'] && $s['device'] !== '') {
                    $extAnswer['device'] = $s['device'];
                    break;
                }
            }
        }

        if ($inTrunk !== '' && $out && !$answeredExts) {
            $direction = self::TRANSIT;
        } elseif ($inTrunk !== '') {
            $direction = self::INBOUND;
        } elseif ($out) {
            $direction = self::OUTBOUND;
        } else {
            $direction = self::INTERNAL;
        }

        // Path: where the call came from, then everyone who answered, in order.
        $path = [$inTrunk !== ''
            ? $this->node('trunk', $inTrunk, $src, true)
            : $this->node('ext', $src, $src, true)];
        foreach ($answered as $s) {
            $node = $this->node($s['kind'], $s['id'], $s['number'], true, $s['device']);
            $last = end($path);
            if ($last['kind'] === $node['kind'] && $last['id'] === $node['id'] && $last['number'] === $node['number']) {
                continue;
            }
            $path[] = $node;
        }
        if (!$answered) {
            // Nobody answered: show who was tried (distinct, a few at most).
            $seen = [];
            foreach ($steps as $s) {
                $key = $s['kind'] . '|' . $s['id'] . '|' . $s['number'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $path[] = $this->node($s['kind'], $s['id'], $s['number'], false, $s['device']);
                if (count($seen) >= 3) {
                    break;
                }
            }
        }

        $dialed = (string) ($first['did'] ?? '');
        if ($dialed === '') {
            $dialed = (string) ($first['dst'] ?? '');
        }
        // dst is the last dialplan extension, not always a number: "s"/"t" in
        // an IVR, labels like "BUSY", or the caller's own number in some
        // dialplans. Then what was reached is the better answer.
        if (!preg_match('/^[0-9*#+]+$/', $dialed) || $dialed === $src) {
            $dialed = $out['number'] ?? ($extAnswer['id'] ?? ($steps[0]['number'] ?? ''));
            if (!preg_match('/^[0-9*#+]+$/', $dialed)) {
                $dialed = '';
            }
        }

        return [
            'direction' => $direction,
            'transferred' => count($answered) > 1 && count($path) > 2,
            'caller_number' => $src,
            'caller_name' => $callerName,
            'in_trunk' => $inTrunk,
            'in_trunk_title' => $inTrunk !== '' ? $this->trunkTitle($inTrunk) : '',
            'dialed_number' => $dialed,
            'out_trunk' => $out['id'] ?? '',
            'out_trunk_title' => $out ? $this->trunkTitle($out['id']) : '',
            'out_number' => $out['number'] ?? '',
            'answered_ext' => $extAnswer['id'] ?? '',
            'answered_name' => $extAnswer ? ($this->userNames[$extAnswer['id']] ?? '') : '',
            'answered_device' => $extAnswer['device'] ?? '',
            'answered_ai' => $aiAnswer ? [
                'id' => $aiAnswer['id'],
                'title' => $this->aiTitle($aiAnswer['id']),
                'number' => $aiAnswer['number'],
            ] : null,
            'path' => $path,
        ];
    }

    /** @return array{kind: string, id: string, number: string, label: string, answered: bool, device: string} */
    private function node(string $kind, string $id, string $number, bool $answered, string $device = ''): array
    {
        $label = match ($kind) {
            'trunk' => $this->trunkTitle($id),
            'ext' => $this->userNames[$id] ?? '',
            'ai' => $this->aiTitle($id),
            default => '',
        };
        return ['kind' => $kind, 'id' => $id, 'number' => $number, 'label' => $label, 'answered' => $answered, 'device' => $device];
    }
}
