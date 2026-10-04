<?php

/**
 * Call-centre statistics for the Queue Report Centre, computed from one row
 * per queued call (see QueueReportRepository::calls()).
 *
 * Definitions (shown in the page's help):
 * - offered:        calls that entered a queue
 * - answered:       an agent connected (CONNECT)
 * - lost:           the caller hung up (ABANDON) or left without an agent
 *                   (EXITWITHTIMEOUT / EXITEMPTY / EXITWITHKEY)
 * - short abandon:  lost within SHORT_ABANDON seconds; left out of the
 *                   service level, the caller had no real chance to be served
 * - service level:  answered within $slSeconds / (offered - short abandons)
 * - ASA:            average wait of answered calls
 * - AHT:            average talk time of answered calls
 */
final class QueueStats
{
    public const SHORT_ABANDON = 5;
    /** Upper bounds (seconds) of the wait-time buckets; the last one is open. */
    public const WAIT_BUCKETS = [10, 20, 30, 60, 120, 300];

    public static function agentExt(string $agent): string
    {
        // Members are "Local/3001@from-internal-pbx/n" or named "Temsilci 3001".
        if (preg_match('#(?:Local|PJSIP)/(\d+)#', $agent, $m)) {
            return $m[1];
        }
        return preg_replace('/\D/', '', $agent) ?? '';
    }

    /** Digits only, last 10: 0532…, 90532… and +90532… are the same caller. */
    public static function normalizeNumber(string $n): string
    {
        $d = preg_replace('/\D/', '', $n) ?? '';
        return strlen($d) > 10 ? substr($d, -10) : $d;
    }

    /**
     * @param list<array<string, mixed>> $calls one row per (call, queue)
     * @return array<string, mixed>
     */
    public static function kpis(array $calls, int $slSeconds): array
    {
        $offered = count($calls);
        $answered = $lost = $short = $inSl = $waitSum = $talkSum = $talkN = $maxWait = $lostWaitSum = 0;
        foreach ($calls as $c) {
            $wait = (int) $c['wait'];
            $maxWait = max($maxWait, $wait);
            if ($c['answered']) {
                $answered++;
                $waitSum += $wait;
                if ($wait <= $slSeconds) {
                    $inSl++;
                }
                if ((int) $c['talk'] > 0) {
                    $talkSum += (int) $c['talk'];
                    $talkN++;
                }
            } elseif ($c['lost'] !== '') {
                $lost++;
                $lostWaitSum += $wait;
                if ($wait < self::SHORT_ABANDON) {
                    $short++;
                }
            }
        }
        $slBase = $offered - $short;
        return [
            'offered' => $offered,
            'answered' => $answered,
            'lost' => $lost,
            'short_abandons' => $short,
            'answer_rate' => self::pct($answered, $offered),
            'lost_rate' => self::pct($lost, $offered),
            'service_level' => self::pct($inSl, $slBase),
            'asa' => $answered ? (int) round($waitSum / $answered) : 0,
            'aht' => $talkN ? (int) round($talkSum / $talkN) : 0,
            'talk_total' => $talkSum,
            'max_wait' => $maxWait,
            'avg_lost_wait' => $lost ? (int) round($lostWaitSum / $lost) : 0,
        ];
    }

    /** @return array<string, array<string, mixed>> queue_name => kpis */
    public static function byQueue(array $calls, int $slSeconds): array
    {
        $groups = [];
        foreach ($calls as $c) {
            $groups[$c['queue_name']][] = $c;
        }
        ksort($groups);
        return array_map(fn($g) => self::kpis($g, $slSeconds), $groups);
    }

    /**
     * Agent performance. Answered calls come from the call rows; missed rings,
     * pauses and dispositions are per agent and passed in.
     *
     * @param array<string, array{count: int, ring_ms: int}> $ringMisses ext => RINGNOANSWER totals
     * @param array<string, array{count: int, seconds: int}> $pauses     ext => pause totals in the period
     * @param array<string, int>                             $notes      ext => saved call notes
     * @return array<string, array<string, mixed>> ext => stats, most answered first
     */
    public static function byAgent(array $calls, array $ringMisses, array $pauses, array $notes): array
    {
        $agents = [];
        $blank = ['answered' => 0, 'talk_total' => 0, 'talk_max' => 0, 'ring_total' => 0, 'agent_hangups' => 0, 'transfers' => 0,
            'missed_rings' => 0, 'pause_count' => 0, 'pause_seconds' => 0, 'notes' => 0];
        $totalAnswered = 0;
        foreach ($calls as $c) {
            if (!$c['answered']) {
                continue;
            }
            $ext = self::agentExt((string) $c['agent']);
            if ($ext === '') {
                continue;
            }
            $a = $agents[$ext] ?? $blank;
            $a['answered']++;
            $a['talk_total'] += (int) $c['talk'];
            $a['talk_max'] = max($a['talk_max'], (int) $c['talk']);
            $a['ring_total'] += (int) $c['ring'];
            $a['agent_hangups'] += $c['agent_hangup'] ? 1 : 0;
            $a['transfers'] += $c['transferred'] ? 1 : 0;
            $agents[$ext] = $a;
            $totalAnswered++;
        }
        foreach ($ringMisses as $ext => $r) {
            $agents[$ext] = ($agents[$ext] ?? $blank);
            $agents[$ext]['missed_rings'] = (int) $r['count'];
        }
        foreach ($pauses as $ext => $p) {
            $agents[$ext] = ($agents[$ext] ?? $blank);
            $agents[$ext]['pause_count'] = (int) $p['count'];
            $agents[$ext]['pause_seconds'] = (int) $p['seconds'];
        }
        foreach ($notes as $ext => $n) {
            $agents[$ext] = ($agents[$ext] ?? $blank);
            $agents[$ext]['notes'] = (int) $n;
        }
        foreach ($agents as &$a) {
            $a['share'] = self::pct($a['answered'], $totalAnswered);
            $a['aht'] = $a['answered'] ? (int) round($a['talk_total'] / $a['answered']) : 0;
            $a['avg_ring'] = $a['answered'] ? (int) round($a['ring_total'] / $a['answered']) : 0;
            // Of the times the queue rang this agent, how often they picked up.
            $a['pickup_rate'] = self::pct($a['answered'], $a['answered'] + $a['missed_rings']);
        }
        unset($a);
        uasort($agents, fn($x, $y) => [$y['answered'], $y['talk_total']] <=> [$x['answered'], $x['talk_total']]);
        return $agents;
    }

    /**
     * Offered / answered / lost per hour of day (0-23) and per date.
     * @return array{hours: array<int, array<string, int>>, days: array<string, array<string, int>>}
     */
    public static function distribution(array $calls): array
    {
        $hours = array_fill(0, 24, ['offered' => 0, 'answered' => 0, 'lost' => 0]);
        $days = [];
        foreach ($calls as $c) {
            $h = (int) date('G', (int) $c['enter_ts']);
            $d = date('Y-m-d', (int) $c['enter_ts']);
            $days[$d] ??= ['offered' => 0, 'answered' => 0, 'lost' => 0];
            foreach ([&$hours[$h], &$days[$d]] as &$slot) {
                $slot['offered']++;
                if ($c['answered']) {
                    $slot['answered']++;
                } elseif ($c['lost'] !== '') {
                    $slot['lost']++;
                }
            }
            unset($slot);
        }
        ksort($days);
        return ['hours' => $hours, 'days' => $days];
    }

    /** @return list<array{from: int, to: ?int, answered: int, lost: int}> */
    public static function waitBuckets(array $calls): array
    {
        $out = [];
        $prev = 0;
        foreach (self::WAIT_BUCKETS as $max) {
            $out[] = ['from' => $prev, 'to' => $max, 'answered' => 0, 'lost' => 0];
            $prev = $max;
        }
        $out[] = ['from' => $prev, 'to' => null, 'answered' => 0, 'lost' => 0];
        foreach ($calls as $c) {
            if (!$c['answered'] && $c['lost'] === '') {
                continue;
            }
            $w = (int) $c['wait'];
            foreach ($out as &$b) {
                if ($b['to'] === null || $w < $b['to']) {
                    $b[$c['answered'] ? 'answered' : 'lost']++;
                    break;
                }
            }
            unset($b);
        }
        return $out;
    }

    /**
     * Lost calls and whether someone called back: a later answered queue
     * call from the same number, or an outgoing call to it.
     *
     * @param array<string, list<int>> $outgoing normalized number => timestamps of outgoing calls
     * @return list<array<string, mixed>> newest first
     */
    public static function lostCalls(array $calls, array $outgoing): array
    {
        $answeredLater = [];
        foreach ($calls as $c) {
            if ($c['answered']) {
                $answeredLater[self::normalizeNumber((string) $c['caller'])][] = (int) $c['enter_ts'];
            }
        }
        $out = [];
        foreach ($calls as $c) {
            if ($c['answered'] || $c['lost'] === '') {
                continue;
            }
            $num = self::normalizeNumber((string) $c['caller']);
            $ts = (int) $c['enter_ts'];
            $back = null;
            $how = '';
            if ($num !== '' && strlen($num) >= 7) {
                foreach ($outgoing[$num] ?? [] as $t) {
                    if ($t > $ts && ($back === null || $t < $back)) {
                        $back = $t;
                        $how = 'called_back';
                    }
                }
                foreach ($answeredLater[$num] ?? [] as $t) {
                    if ($t > $ts && ($back === null || $t < $back)) {
                        $back = $t;
                        $how = 'called_again';
                    }
                }
            }
            $out[] = $c + ['resolved_at' => $back, 'resolved_how' => $how];
        }
        usort($out, fn($a, $b) => $b['enter_ts'] <=> $a['enter_ts']);
        return $out;
    }

    /**
     * Numbers that called more than once (no answer the first time, or a
     * problem not solved on the first call).
     * @return list<array<string, mixed>>
     */
    public static function repeatCallers(array $calls, int $min = 2): array
    {
        $by = [];
        foreach ($calls as $c) {
            $num = self::normalizeNumber((string) $c['caller']);
            if (strlen($num) < 7) {
                continue;   // anonymous / internal
            }
            $r = $by[$num] ?? ['caller' => (string) $c['caller'], 'calls' => 0, 'answered' => 0, 'lost' => 0, 'first' => PHP_INT_MAX, 'last' => 0];
            $r['calls']++;
            $r['answered'] += $c['answered'] ? 1 : 0;
            $r['lost'] += (!$c['answered'] && $c['lost'] !== '') ? 1 : 0;
            $r['first'] = min($r['first'], (int) $c['enter_ts']);
            $r['last'] = max($r['last'], (int) $c['enter_ts']);
            $by[$num] = $r;
        }
        $out = array_values(array_filter($by, fn($r) => $r['calls'] >= $min));
        usort($out, fn($a, $b) => [$b['calls'], $b['last']] <=> [$a['calls'], $a['last']]);
        return $out;
    }

    private static function pct(int $part, int $whole): float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
    }
}
