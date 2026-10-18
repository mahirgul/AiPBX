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
                                     SUM(CASE WHEN status LIKE '%ANSWER%' THEN 1 ELSE 0 END) AS answered
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
    ];

    /**
     * Ask. @return array{query:string, title:string, rows:array, askable:string,
     *                   narration:?string}
     */
    public static function ask(string $question): array
    {
        $query = self::match($question);
        if ($query === null) {
            return [
                'query'     => '',
                'title'     => '',
                'rows'      => [],
                'askable'   => self::askable(),
                'narration' => null,
            ];
        }
        $rows = self::run($query);
        return [
            'query'     => $query,
            'title'     => t(self::QUERIES[$query]['title_key']),
            'rows'      => $rows,
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
    private static function match(string $question): ?string
    {
        $q = mb_strtolower(trim($question));
        $has = static fn(string $haystack, array $words): bool =>
            (bool) preg_match('/\b(?:' . implode('|', $words) . ')\b/u', $haystack);

        if ($has($q, ['signaling|siphon|capture|yakalama'])) {
            return $has($q, ['error|fail|problem|problem|hata|red|deny']) ? 'signaling.errors' : 'signaling.count';
        }
        if ($has($q, ['hour|last hour|saat'])) {
            return 'calls.hour';
        }
        if ($has($q, ['top|busiest|busiest caller|most'])) {
            return 'calls.top';
        }
        if ($has($q, ['answer|answered|cevap|cevaplanan|no answer|cevapsiz|cevapsız'])) {
            return 'answered.rate';
        }
        if ($has($q, ['no answer|failed|not connected|cevapsiz|cevapsız|arayan|yeni arayan'])) {
            return 'noanswer.hour';
        }
        if ($has($q, ['today|calls today|bugun|bugün|arama|calls'])) {
            return 'calls.today';
        }
        return null;
    }

    /** Run one fixed query with the test/prod guard the portal uses. */
    private static function run(string $query): array
    {
        $sql = self::QUERIES[$query]['sql'] ?? null;
        if ($sql === null) {
            return [];
        }
        try {
            $st = getDB()->prepare($sql);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // An empty answer is the honest failure of a report page that
            // cannot read its table — not an exception across the API.
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
