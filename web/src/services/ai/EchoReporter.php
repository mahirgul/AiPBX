<?php
/**
 * AI → Echo: "ask your phone system" for this server, read-only.
 *
 * A question becomes typed QUERY (one of a fixed list — what the answer can
 * use is bounded, exactly like the assistant's actions), run against this
 * server's own data (cdrs, pbx_signaling_events). The rows are formatted
 * deterministically; a language model, when one is configured, only narrates
 * them. No model is needed for the numbers — the model only says them in
 * prose. A question that names no query is answered with the list of what
 * CAN be asked, which is the honest "I don't do that (yet)".
 *
 * Read-only by construction: every query here is a fixed SELECT against the
 * same tables the portal's own report pages read, with bound parameters
 * where a period filter is used. Nothing writes.
 */

require_once __DIR__ . '/AssistantModel.php';

class EchoReporter
{
    /**
     * The queries, and nothing else. One entry: title key, time window,
     * SQL and row formatter. The SQL returns AGGREGATES — counts and
     * durations, not raw call detail: the portal already has pages for
     * looking at individual calls, Echo is for the shape of the day.
     */
    private const QUERIES = [
        'calls.today' => [
            'title_key'   => 'echo.q_calls_today',
            'sql'         => "SELECT COUNT(*) AS calls,
                                     COALESCE(SUM(billsec), 0)/60 AS minutes
                              FROM cdrs
                              WHERE start_time >= CURDATE()",
            'labels'      => ['echo.lbl_calls', 'echo.lbl_minutes'],
        ],
        'calls.hour' => [
            'title_key'   => 'echo.q_calls_hour',
            'sql'         => "SELECT COUNT(*) AS calls FROM cdrs
                              WHERE start_time >= (NOW() - INTERVAL 1 HOUR)",
            'labels'      => ['echo.lbl_calls'],
        ],
        'answered.rate' => [
            'title_key'   => 'echo.q_answered_rate',
            'sql'         => "SELECT COUNT(*) AS total,
                                     SUM(CASE WHEN status = 'ANSWERED' THEN 1 ELSE 0 END) AS answered
                              FROM cdrs
                              WHERE start_time >= CURDATE()",
            'labels'      => ['echo.lbl_total', 'echo.lbl_answered'],
        ],
        'calls.top' => [
            'title_key'   => 'echo.q_calls_top',
            'sql'         => "SELECT caller_num, COUNT(*) AS calls FROM cdrs
                              WHERE start_time >= CURDATE() AND caller_num <> ''
                              GROUP BY caller_num ORDER BY calls DESC LIMIT 5",
            'labels'      => ['echo.lbl_caller', 'echo.lbl_calls'],
        ],
        'noanswer.hour' => [
            'title_key'   => 'echo.q_noanswer_hour',
            'sql'         => "SELECT caller_num, did, duration FROM cdrs
                              WHERE start_time >= (NOW() - INTERVAL 1 HOUR)
                                AND billsec = 0
                              ORDER BY start_time DESC LIMIT 5",
            'labels'      => ['echo.lbl_caller', 'echo.lbl_called', 'echo.lbl_seconds'],
        ],
        'signaling.count' => [
            'title_key'   => 'echo.q_signaling_count',
            'sql'         => "SELECT COUNT(*) AS packets FROM pbx_signaling_events
                              WHERE ts >= (NOW() - INTERVAL 24 HOUR)",
            'labels'      => ['echo.lbl_packets'],
        ],
        'signaling.errors' => [
            'title_key'   => 'echo.q_signaling_errors',
            'sql'         => "SELECT status_code, COUNT(*) AS seen FROM pbx_signaling_events
                              WHERE ts >= (NOW() - INTERVAL 24 HOUR)
                                AND status_code IN ('400','403','404','407','480','486','500','503','600','603')
                              GROUP BY status_code ORDER BY seen DESC LIMIT 6",
            'labels'      => ['echo.lbl_code', 'echo.lbl_seen'],
        ],
        'extension.forwarding' => [
            'title_key'   => 'echo.q_extension_forwarding',
            'sql'         => '',
            'labels'      => [
                'echo.lbl_extension',
                'echo.lbl_name',
                'echo.lbl_forward_always',
                'echo.lbl_forward_busy',
                'echo.lbl_forward_noanswer',
                'echo.lbl_dnd',
            ],
        ],
    ];

    /**
     * Ask. @return array{query:string, title:string, rows:array, labels:array,
     *                   askable:string, narration:?string}
     *
     * @param array{is_admin?:bool, extension?:?string}|null $scope
     */
    public static function ask(string $question, ?array $scope = null): array
    {
        $query = self::match($question);
        if ($query === null) {
            return [
                'query'     => '',
                'title'     => '',
                'rows'      => [],
                'labels'    => [],
                'askable'   => self::askable(),
                'narration' => null,
            ];
        }
        $rows = self::run($query, $question, $scope);
        return [
            'query'     => $query,
            'title'     => t(self::QUERIES[$query]['title_key']),
            'rows'      => $rows,
            'labels'    => array_map('t', self::QUERIES[$query]['labels'] ?? []),
            'askable'   => '',
            'narration' => self::narrate($question, $query, $rows),
        ];
    }

    public static function askable(): string
    {
        $titles = [];
        foreach (self::QUERIES as $spec) {
            $titles[] = t($spec['title_key']);
        }
        return implode(' · ', $titles);
    }

    /** Question → one of the fixed queries, EN/TR keyword match, or null. */
    public static function match(string $question): ?string
    {
        $q = mb_strtolower(trim($question));
        $has = static fn(string $haystack, array $words): bool =>
            (bool) preg_match('/\b(?:' . implode('|', $words) . ')\b/u', $haystack);

        if ($has($q, ['signaling', 'siphon', 'capture', 'yakalama'])) {
            return $has($q, ['error', 'fail', 'problem', 'hata', 'red', 'deny']) ? 'signaling.errors' : 'signaling.count';
        }
        if ($has($q, ['forward', 'forwarding', 'forwarded', 'yonlendir', 'yönlendir', 'yonlendirilmis', 'yönlendirilmiş', 'nereye', 'dnd', 'rahatsiz', 'rahatsız'])) {
            return 'extension.forwarding';
        }
        if ($has($q, ['hour', 'last hour', 'saat'])) {
            return 'calls.hour';
        }
        if ($has($q, ['top', 'busiest', 'busiest caller', 'most'])) {
            return 'calls.top';
        }
        if ($has($q, ['no answer', 'failed', 'not connected', 'cevapsiz', 'cevapsız', 'arayan', 'yeni arayan'])) {
            return 'noanswer.hour';
        }
        if ($has($q, ['answer', 'answered', 'cevap', 'cevaplanan'])) {
            return 'answered.rate';
        }
        if ($has($q, ['today', 'calls today', 'bugun', 'bugün', 'arama', 'calls'])) {
            return 'calls.today';
        }
        return null;
    }

    /** Run one fixed query with optional non-admin user-scoping. */
    private static function run(string $query, string $question = '', ?array $scope = null): array
    {
        $isAdmin = $scope === null || !empty($scope['is_admin']);
        $ownExt = trim((string) ($scope['extension'] ?? ''));

        if ($query === 'extension.forwarding') {
            return self::runExtensionForwarding($question, $isAdmin, $ownExt);
        }

        $sql = self::QUERIES[$query]['sql'] ?? null;
        if ($sql === null || $sql === '') {
            return [];
        }

        if (!$isAdmin) {
            if (str_starts_with($query, 'signaling.')) {
                return [];
            }
            if ($ownExt === '') {
                return [];
            }
            $params = [$ownExt, $ownExt];
            if (str_contains($sql, 'GROUP BY')) {
                $sql = str_replace('GROUP BY', 'AND (caller_num = ? OR did = ?) GROUP BY', $sql);
            } elseif (str_contains($sql, 'ORDER BY')) {
                $sql = str_replace('ORDER BY', 'AND (caller_num = ? OR did = ?) ORDER BY', $sql);
            } else {
                $sql .= ' AND (caller_num = ? OR did = ?)';
            }
        } else {
            $params = [];
        }

        try {
            $st = getDB()->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // An empty answer is the honest failure of a report page that
            // cannot read its table — not an exception across the API.
            return [];
        }
    }

    /** Read-only extension forwarding & DND status (scoped to own extension for non-admins). */
    private static function runExtensionForwarding(string $question, bool $isAdmin, string $ownExt): array
    {
        $requestedExt = '';
        if (preg_match('/\b(\d{2,6})\b/', $question, $m)) {
            $requestedExt = $m[1];
        }

        if (!$isAdmin) {
            if ($ownExt === '') {
                return [];
            }
            if ($requestedExt !== '' && $requestedExt !== $ownExt) {
                return [];
            }
            $requestedExt = $ownExt;
        }

        try {
            if ($requestedExt !== '') {
                $st = getDB()->prepare(
                    "SELECT extension, full_name,
                            COALESCE(call_forward_number, '') AS call_forward_number,
                            COALESCE(cf_busy_number, '') AS cf_busy_number,
                            COALESCE(cf_noanswer_number, '') AS cf_noanswer_number,
                            COALESCE(dnd_enabled, 0) AS dnd_enabled
                     FROM sys_users
                     WHERE extension = ? AND is_active = 1
                     LIMIT 1"
                );
                $st->execute([$requestedExt]);
                return $st->fetchAll(PDO::FETCH_ASSOC);
            }

            $st = getDB()->prepare(
                "SELECT extension, full_name,
                        COALESCE(call_forward_number, '') AS call_forward_number,
                        COALESCE(cf_busy_number, '') AS cf_busy_number,
                        COALESCE(cf_noanswer_number, '') AS cf_noanswer_number,
                        COALESCE(dnd_enabled, 0) AS dnd_enabled
                 FROM sys_users
                 WHERE is_active = 1
                   AND (COALESCE(call_forward_number, '') <> ''
                     OR COALESCE(cf_busy_number, '') <> ''
                     OR COALESCE(cf_noanswer_number, '') <> ''
                     OR COALESCE(dnd_enabled, 0) = 1)
                 ORDER BY extension ASC
                 LIMIT 20"
            );
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * One short narration of the rows, by one model (same chain as the
     * assistant), or null when none is configured. The rows carry the truth;
     * the model only words it. This is deliberately the only "model" call in
     * the file, and the only one whose output is prose.
     */
    private static function narrate(string $question, string $query, array $rows): ?string
    {
        require_once __DIR__ . '/AssistantModel.php';
        $data = json_encode($rows, JSON_UNESCAPED_UNICODE) ?: '[]';
        $prompt = "Answer in the user's language, at most 3 sentences, using ONLY the data."
            . " Question: " . mb_substr($question, 0, 200) . "\n"
            . "Data (JSON): {$data}\n"
            . "If the data is empty, say there is nothing recorded for that period.";
        try {
            $reply = AssistantModel::ask($prompt);
            return is_string($reply) && trim($reply) !== '' ? mb_substr($reply, 0, 900) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
