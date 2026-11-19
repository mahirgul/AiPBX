<?php
/**
 * AI -> Assistant: turning a plain-language request into a PBX change.
 *
 * The division of labour is the whole design, and it is deliberate:
 *
 *   A language model may only NAME an action and its arguments.
 *   It never writes a configuration file, never writes SQL, and never touches
 *   Asterisk. This code validates what it said, and the caller performs the
 *   change through the SAME services the portal's own pages use — so an
 *   invented action, an invented field or an invented value cannot reach the
 *   PBX. A model that is wrong is refused, not obeyed.
 *
 * Why: this is the lesson of the Echo service, where the model picks an intent
 * and an optional search term and the query itself is built deterministically.
 * A prompt injection or a hallucination there produces a bad answer; here it
 * would produce a broken phone system, so the same rule is applied harder.
 *
 * Everything in this class is a pure function of its arguments: no database,
 * no clock, no network. The caller passes the catalog of what exists (see
 * context()) — which is also what makes every rule below testable.
 *
 * The rule-based planner is always available and runs FIRST: the assistant
 * must be useful on a server with no AI provider configured, and a cloud model
 * must never be a single point of failure for configuring the PBX.
 */

class AssistantPlanner
{
    /**
     * Every change the assistant may propose, and nothing else.
     *
     * 'fields' maps an argument to its kind (see FIELD_KINDS). A model reply
     * naming anything outside this table is refused.
     */
    public const ACTIONS = [
        'extension.forward' => [
            'title_key' => 'ai_assistant.act_extension_forward',
            'module' => 'extensions',
            'risk' => 'medium',
            'fields' => [
                'extension' => 'extension',
                'target' => 'target',
                'enabled' => 'bool',
                'condition' => 'forward_condition',
            ],
            // 'enabled' is required, not defaulted: "forward 101 to 203" must
            // not be guessed into an on/off decision by a default. 'target' is
            // enforced in build() whenever enabled is true.
            'required' => ['extension', 'enabled'],
        ],
        'extension.dnd' => [
            'title_key' => 'ai_assistant.act_extension_dnd',
            'module' => 'extensions',
            'risk' => 'low',
            'fields' => ['extension' => 'extension', 'enabled' => 'bool'],
            'required' => ['extension', 'enabled'],
        ],
        'extension.voicemail' => [
            'title_key' => 'ai_assistant.act_extension_voicemail',
            'module' => 'extensions',
            'risk' => 'low',
            'fields' => ['extension' => 'extension', 'enabled' => 'bool'],
            'required' => ['extension', 'enabled'],
        ],
        'extension.rename' => [
            'title_key' => 'ai_assistant.act_extension_rename',
            'module' => 'extensions',
            'risk' => 'low',
            'fields' => ['extension' => 'extension', 'name' => 'name'],
            'required' => ['extension', 'name'],
        ],
        'ringgroup.members' => [
            'title_key' => 'ai_assistant.act_ringgroup_members',
            'module' => 'ring_groups',
            'risk' => 'medium',
            'fields' => ['group' => 'ringgroup', 'extensions' => 'extension_list'],
            'required' => ['group', 'extensions'],
        ],
        'ringgroup.timeout' => [
            'title_key' => 'ai_assistant.act_ringgroup_timeout',
            'module' => 'ring_groups',
            'risk' => 'low',
            'fields' => ['group' => 'ringgroup', 'seconds' => 'seconds'],
            'required' => ['group', 'seconds'],
        ],
        'queue.members' => [
            'title_key' => 'ai_assistant.act_queue_members',
            'module' => 'queues',
            'risk' => 'medium',
            'fields' => ['queue' => 'queue', 'extensions' => 'extension_list'],
            'required' => ['queue', 'extensions'],
        ],
        'queue.timeout' => [
            'title_key' => 'ai_assistant.act_queue_timeout',
            'module' => 'queues',
            'risk' => 'low',
            'fields' => ['queue' => 'queue', 'seconds' => 'seconds'],
            'required' => ['queue', 'seconds'],
        ],
        'timecondition.create' => [
            'title_key' => 'ai_assistant.act_timecondition_create',
            'module' => 'time_conditions',
            'risk' => 'medium',
            'fields' => [
                'title' => 'title',
                'open' => 'clock',
                'close' => 'clock',
                'days' => 'weekdays',
            ],
            'required' => ['title', 'open', 'close', 'days'],
        ],
        'voicemail.greeting' => [
            'title_key' => 'ai_assistant.act_voicemail_greeting',
            'module' => 'voicemail',
            'risk' => 'low',
            'fields' => ['extension' => 'extension', 'text' => 'greeting'],
            'required' => ['extension', 'text'],
        ],
    ];

    /** Longest greeting the assistant accepts (a spoken greeting, not an essay). */
    public const MAX_GREETING = 600;

    /** Ring/queue timeouts: longer is not wrong so much as useless. */
    public const MAX_SECONDS = 300;

    /** Days a time condition may name. */
    public const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Resolves a request into a validated plan.
     *
     * @param string        $request plain-language request from the administrator
     * @param array         $context what exists on this server (see context())
     * @param callable|null $ask     fn(string $prompt): ?string — asks a language
     *                               model; null when no provider is configured
     * @return array{ok:bool, action?:string, arguments?:array, source?:string,
     *               message_key?:string, message_params?:array}
     */
    public static function plan(string $request, array $context, ?callable $ask = null): array
    {
        $request = trim($request);
        if ($request === '') {
            return self::refuse('ai_assistant.err_empty');
        }

        // 1. The rules first: free, instant, and identical every time.
        $plan = self::fromRules($request, $context);
        if ($plan !== null) {
            return $plan;
        }

        // 2. Only then a model, and only when one is configured.
        if ($ask !== null) {
            $reply = $ask(self::prompt($request, $context));
            $fromModel = self::fromModelReply($reply, $context);
            if ($fromModel !== null) {
                return $fromModel;
            }
        }

        // 3. Saying "I did not understand" is a correct answer. Guessing is not.
        return self::refuse('ai_assistant.err_understood', ['request' => mb_substr($request, 0, 120)]);
    }

    /**
     * The rule-based planner — always on. Understands the everyday phrasings in
     * English and Turkish, which is what most requests actually look like.
     * Returns null when it is not confident, so the caller can ask a model.
     */
    public static function fromRules(string $request, array $context): ?array
    {
        $text = self::normalise($request);

        // 1a. Turn off forwarding: "19000 yönlendirmeyi kapat / iptal et / kaldır",
        // "forward off 19000", "cancel forwarding for 19000". Checked before the
        // 2-number forwarding rule because turning off needs only the extension.
        $fwdOff = preg_match('/\b(?:off|disable|disabled|cancel|remove|clear|stop|kapat|kapali|kapalı|iptal|kaldir|kaldır|devre disi|devre dışı)\b/u', $text);
        $fwdCond = null;
        if (preg_match('/\b(?:busy|mesgul|meşgul|mesgulse|meşgulse)\b/u', $text)) {
            $fwdCond = 'busy';
        } elseif (preg_match('/\b(?:no answer|noanswer|unanswered|cevap vermez|cevap vermezse|yanit vermez|yanıt vermez|yanit vermezse|yanıt vermezse)\b/u', $text)) {
            $fwdCond = 'noanswer';
        }
        if (preg_match('/\b(?:forward|forwarding|divert|yonlendir|yönlendir|yonlendirme|yönlendirme|yonlendirmeyi|yönlendirmeyi|yonlendirmesi|yönlendirmesi)\b/u', $text)) {
            $numbers = self::numbersIn($text);
            if ($fwdOff && count($numbers) >= 1) {
                $args = ['extension' => $numbers[0], 'enabled' => false];
                if ($fwdCond !== null) {
                    $args['condition'] = $fwdCond;
                }
                return self::build('extension.forward', $args, $context, 'rules');
            }
            // 1b. "forward 101 to 202" / "forward 19000 to +49 170 1234567" /
            // "101 numaralı dahiliyi 202'ye yönlendir".
            if (!$fwdOff && count($numbers) >= 2) {
                $after = null;
                if (preg_match('/\bto\s+((?:\+\s*)?(?:\(\d{1,5}\)|\d+)(?:[\s\-\/.]+(?:\(\d{1,5}\)|\d+))*)/u', $text, $m)) {
                    $cleaned = preg_replace('/[^0-9+]/', '', $m[1]);
                    if (strlen(ltrim($cleaned, '+')) >= 2) {
                        $after = $cleaned;
                    }
                }
                $args = [
                    'extension' => $numbers[0],
                    'target' => $after ?? $numbers[count($numbers) - 1],
                    'enabled' => true,
                ];
                if ($fwdCond !== null) {
                    $args['condition'] = $fwdCond;
                }
                return self::build('extension.forward', $args, $context, 'rules');
            }
        }

        // "19000 rahatsız etmeyin aç/kapat", "enable/disable dnd for 19000".
        if (preg_match('/\b(?:dnd|do not disturb|rahatsiz etmeyin|rahatsız etmeyin|rahatsiz etme|rahatsız etme)\b/u', $text)
            && preg_match('/\b(\d{2,6})\b/u', $text, $m)) {
            if (preg_match('/\?|\b(?:status|durum|durumu|nedir|mi|mı|mu|mü)\b|^(?:is|are)\b/u', $text)) {
                return null;
            }
            $off = preg_match('/\b(?:off|disable|disabled|kapat|kapali|kapalı|devre disi|devre dışı|kaldir|kaldır)\b/u', $text);
            $on = preg_match('/\b(?:on|enable|enabled|activate|ac|aç|etkinleştir|etkinlestir)\b/u', $text);
            // A status question is read-only; never infer an on command from missing 'off'.
            if (!$on && !$off) {
                return null;
            }
            return self::build('extension.dnd', [
                'extension' => $m[1], 'enabled' => !$off,
            ], $context, 'rules');
        }

        // "19000'in adı Ayşe Yılmaz", "rename 19000 to Ayşe Yılmaz".
        if (preg_match('/\b(?:rename|name|adi|adı|adini|adını|ismi|ismini)\b/u', $text)
            && preg_match('/\b(\d{2,6})\b/u', $request, $em)) {
            $newName = null;
            if (preg_match('/["“](.+?)["”]/u', $request, $qm)) {
                $newName = trim($qm[1]);
            } elseif (preg_match('/\b(?:to|as|adi|adı|adini|adını|ismi|ismini)\s+(.+?)(?:\s+(?:yap|olsun))?$/iu', $request, $nm)) {
                $newName = trim($nm[1]);
            }
            if ($newName !== null && $newName !== '') {
                return self::build('extension.rename', [
                    'extension' => $em[1], 'name' => $newName,
                ], $context, 'rules');
            }
        }

        // "turn on/off voicemail for 101" — the 2-6 digit bound is right here:
        // an extension can never be longer, so a long number is not one.
        if (preg_match('/\b(?:voicemail|voice mail|telesekreter|sesli posta)\b/u', $text)
            && preg_match('/\b(\d{2,6})\b/u', $text, $m)) {
            $off = preg_match('/\b(?:off|disable|disabled|kapat|kapali|kapalı|devre disi|devre dışı)\b/u', $text);
            return self::build('extension.voicemail', [
                'extension' => $m[1], 'enabled' => !$off,
            ], $context, 'rules');
        }

        // "add 101 and 102 to the sales ring group"
        $found = preg_match_all('/\b(\d{2,6})\b/', $text, $exts);
        if (preg_match('/\b(?:ring group|ringgroup|hunt group|grup|arama grubu)\b/u', $text)
            && $found) {
            $group = self::matchNamed($text, $context['ring_groups'] ?? []);
            if ($group !== null) {
                $current = [];
                foreach (($context['ring_groups'] ?? []) as $rg) {
                    if ((int) $rg['id'] === (int) $group) {
                        $current = (array) ($rg['members'] ?? []);
                    }
                }
                if (preg_match('/\b(?:timeout|ring time|sure|süre|zaman asimi|zaman aşımı)\b/u', $text)
                    && preg_match('/\b(\d{1,3})\b\s*(?:s|sec|secs|seconds|saniye)?\b/u', $text, $secs)) {
                    return self::build('ringgroup.timeout', [
                        'group' => $group, 'seconds' => $secs[1],
                    ], $context, 'rules');
                }
                // 'add' means merge into the group's current membership; the
                // membership field always ends up as the final list, which is
                // also what the undo's before-value restores.
                $new = array_values(array_unique(array_merge($current, $exts[1])));
                return self::build('ringgroup.members', [
                    'group' => $group, 'extensions' => $new,
                ], $context, 'rules');
            }
        }

        // "add 101 and 102 to the sales queue" / "sales queue ring time to 45"
        // — mirrors the ring-group block; membership is the final list.
        if (preg_match('/\b(?:queue|call queue|kuyruk)\b/u', $text)) {
            $qgroup = self::matchNamed($text, $context['queues'] ?? []);
            if ($qgroup !== null && $found) {
                if (preg_match('/\b(?:timeout|ring time|max wait|sure|süre|zaman asimi|zaman aşımı)\b/u', $text)
                    && preg_match('/\b(\d{1,4})\b\s*(?:s|sec|secs|seconds|saniye)?\b/u', $text, $qsecs)) {
                    return self::build('queue.timeout', [
                        'queue' => $qgroup, 'seconds' => $qsecs[1],
                    ], $context, 'rules');
                }
                $qmembers = [];
                foreach (($context['queues'] ?? []) as $qq) {
                    if ((int) $qq['id'] === (int) $qgroup) {
                        $qmembers = (array) ($qq['members'] ?? []);
                    }
                }
                return self::build('queue.members', [
                    'queue' => $qgroup,
                    'extensions' => array_values(array_unique(array_merge($qmembers, $exts[1]))),
                ], $context, 'rules');
            }
        }

        // "queue sales greeting" style: a greeting for an extension's voicemail
        if (preg_match('/\b(?:greeting|announcement|anons|karsilama|karşılama)\b/u', $text)
            && preg_match('/\b(\d{2,6})\b/', $text, $m)
            && preg_match('/["“](.+?)["”]/u', $request, $quote)) {
            return self::build('voicemail.greeting', [
                'extension' => $m[1], 'text' => $quote[1],
            ], $context, 'rules');
        }

        return null;
    }

    /**
     * Validates an action named by a model.
     *
     * The reply is expected to be one JSON object. Anything else — prose, a
     * code fence, an unknown action, a field that does not belong, a value out
     * of range — is refused outright: a half-understood change to a phone
     * system is worse than no change.
     */
    /** The model's short text answer ({"action":"reply"}), or null. */
    public static function replyText($reply): ?string
    {
        if (!is_string($reply) || trim($reply) === '') {
            return null;
        }
        $json = self::decodeObject($reply);
        if (($json['action'] ?? '') !== 'reply' || !is_string($json['text'] ?? null)) {
            return null;
        }
        $text = trim(preg_replace('/[\x00-\x08\x0b-\x1f\x7f]+/', ' ', $json['text']) ?? '');
        return $text !== '' ? mb_substr($text, 0, 600) : null;
    }

    public static function fromModelReply($reply, array $context): ?array
    {
        if (!is_string($reply) || trim($reply) === '') {
            return null;
        }
        $json = self::decodeObject($reply);
        $action = isset($json['action']) && is_scalar($json['action'])
            ? trim((string) $json['action']) : '';
        if ($action === '' || !isset(self::ACTIONS[$action])) {
            return null;
        }
        $arguments = $json['arguments'] ?? [];
        if (!is_array($arguments)) {
            return null;
        }
        // Fields the action does not have are a sign of a confused model.
        foreach (array_keys($arguments) as $field) {
            if (!isset(self::ACTIONS[$action]['fields'][$field])) {
                return null;
            }
        }
        return self::build($action, $arguments, $context, 'model');
    }

    /**
     * Validates and shapes one proposed change.
     *
     * @return array{ok:bool, action?:string, arguments?:array, source?:string,
     *               module?:string, risk?:string, message_key?:string,
     *               message_params?:array}
     */
    public static function build(string $action, array $arguments, array $context, string $source): array
    {
        if (!isset(self::ACTIONS[$action])) {
            return self::refuse('ai_assistant.err_unknown_action');
        }
        $spec = self::ACTIONS[$action];

        foreach ($spec['required'] as $field) {
            if (!array_key_exists($field, $arguments)) {
                // Nothing is defaulted: a field the caller (or the model) left
                // out is refused, so an omission can never be silently turned
                // into a change nobody asked for.
                return self::refuse('ai_assistant.err_missing', ['field' => $field]);
            }
        }

        $clean = [];
        foreach ($arguments as $field => $value) {
            $kind = $spec['fields'][$field] ?? null;
            if ($kind === null) {
                return self::refuse('ai_assistant.err_unknown_field', ['field' => $field]);
            }
            $result = self::validateField($kind, $value, $context);
            if ($result === null) {
                return self::refuse('ai_assistant.err_bad_value', ['field' => $field]);
            }
            $clean[$field] = $result;
        }
        if ($action === 'extension.forward' && !empty($clean['enabled']) && !array_key_exists('target', $clean)) {
            return self::refuse('ai_assistant.err_missing', ['field' => 'target']);
        }
        return [
            'ok' => true,
            'action' => $action,
            'arguments' => $clean,
            'source' => $source,
            'module' => $spec['module'],
            'risk' => $spec['risk'],
            'title_key' => $spec['title_key'],
        ];
    }

    /**
     * One field value. Returns the cleaned value, or null when it is not
     * acceptable — never a guess and never a silent default.
     *
     * @return mixed|null
     */
    private static function validateField(string $kind, $value, array $context)
    {
        switch ($kind) {
            case 'extension':
                $ext = self::asExtension($value);
                if ($ext === null || !isset($context['extensions'][$ext])) {
                    return null;   // an extension that does not exist is not a plan
                }
                return $ext;

            case 'target':
                // An extension of this server, or an external number: a
                // forwarding target may legitimately leave the building.
                $ext = self::asExtension($value);
                if ($ext !== null && isset($context['extensions'][$ext])) {
                    return $ext;
                }
                return self::asNumber($value);

            case 'forward_condition':
                return is_string($value) && in_array($value, ['always', 'busy', 'noanswer'], true) ? $value : null;

            case 'extension_list':
                if (!is_array($value)) {
                    return null;
                }
                $out = [];
                foreach ($value as $one) {
                    $ext = self::asExtension($one);
                    if ($ext === null || !isset($context['extensions'][$ext])) {
                        return null;
                    }
                    $out[] = $ext;
                }
                return $out === [] ? null : array_values(array_unique($out));

            case 'ringgroup':
                return self::existsNamed($value, $context['ring_groups'] ?? []);

            case 'queue':
                return self::existsNamed($value, $context['queues'] ?? []);

            case 'seconds':
                if (!is_numeric($value)) {
                    return null;
                }
                $seconds = (int) $value;
                return ($seconds >= 1 && $seconds <= self::MAX_SECONDS) ? $seconds : null;

            case 'bool':
                return self::asBool($value);

            case 'name':
            case 'title':
                $text = is_scalar($value) ? trim((string) $value) : '';
                $length = mb_strlen($text);
                return ($length >= 1 && $length <= 120) ? $text : null;

            case 'text':
                $text = is_scalar($value) ? trim((string) $value) : '';
                $length = mb_strlen($text);
                return ($length >= 1 && $length <= self::MAX_GREETING) ? $text : null;

            case 'greeting':
                // Stricter than 'text': this is read aloud by a voice model,
                // so markup and control characters have no business here.
                $text = is_scalar($value) ? trim((string) $value) : '';
                if (preg_match('/[<>{}\\\\\x00-\x1F]/u', $text)) {
                    return null;
                }
                $length = mb_strlen($text);
                return ($length >= 1 && $length <= self::MAX_GREETING) ? $text : null;

            case 'clock':
                if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($value), $m)) {
                    return null;
                }
                return $m[1] . ':' . $m[2];

            case 'weekdays':
                if (!is_array($value) || $value === []) {
                    return null;
                }
                $days = [];
                foreach ($value as $day) {
                    $day = strtolower(trim((string) $day));
                    if (!in_array($day, self::WEEKDAYS, true)) {
                        return null;
                    }
                    $days[] = $day;
                }
                return array_values(array_unique($days));
        }
        return null;
    }

    /**
     * The prompt. Written so the model has one job: naming an action and its
     * arguments from a closed list. It is told plainly that it cannot change
     * anything, and that "none" is a valid answer.
     */
    /** @param string $userLanguage the user's own language by name ("Turkish"), for a short greeting no model can place */
    public static function prompt(string $request, array $context, string $userLanguage = ''): string
    {
        $lines = [];
        foreach (self::ACTIONS as $action => $spec) {
            $fields = [];
            foreach ($spec['fields'] as $field => $kind) {
                $fields[] = $field . ' (' . $kind . ')';
            }
            $lines[] = '- ' . $action . ': ' . implode(', ', $fields);
        }

        $known = '';
        if (!empty($context['extensions'])) {
            $known .= "\nExtensions: " . implode(', ', array_keys($context['extensions']));
        }
        if (!empty($context['ring_groups'])) {
            $known .= "\nRing groups: " . implode(', ', array_column($context['ring_groups'], 'title'));
        }
        if (!empty($context['queues'])) {
            $known .= "\nQueues: " . implode(', ', array_column($context['queues'], 'title'));
        }

        return "You name ONE configuration action for a phone system and its arguments.\n"
            . "You cannot change anything yourself; another part of the program validates "
            . "your answer and performs the change, and refuses anything not on this list.\n"
            . $known . "\n\nActions:\n" . implode("\n", $lines)
            . "\n\nAnswer with one JSON object and nothing else:\n"
            . '{"action": "<one of the actions above>", "arguments": {...}}' . "\n"
            . 'If it is a greeting or a question about using the phone system that no action covers, answer '
            . '{"action": "reply", "text": "<one or two short sentences in the language of the request>"}. '
            . ($userLanguage !== '' ? "The user's language is {$userLanguage}: use it unless the request is clearly in another language. " : '')
            . 'In a reply never say that you changed something, and never make up numbers, names or settings.' . "\n"
            . 'Otherwise, if the request does not match any action, answer {"action": "none"}.' . "\n\n"
            . "Request: " . $request;
    }

    /**
     * What exists on this server, for validation and for the prompt.
     * Kept as an argument so the planner stays free of the database.
     */
    public static function context(array $extensions, array $ringGroups, array $queues): array
    {
        $map = [];
        foreach ($extensions as $extension) {
            $number = (string) ($extension['extension'] ?? $extension['number'] ?? $extension);
            if (preg_match('/^\d{2,6}$/', $number)) {
                $map[$number] = ['name' => (string) ($extension['name'] ?? '')];
            }
        }
        return [
            'extensions' => $map,
            'ring_groups' => self::nameIndex($ringGroups),
            'queues' => self::nameIndex($queues),
        ];
    }

    /** @return list<array{id:int, title:string}> */
    private static function nameIndex(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? $row['name'] ?? ''));
            if ($title === '') {
                continue;
            }
            $out[] = ['id' => (int) ($row['id'] ?? 0), 'title' => $title]
                + (isset($row['members']) ? ['members' => (array) $row['members']] : []);
        }
        return $out;
    }

    private static function refuse(string $key, array $params = []): array
    {
        return ['ok' => false, 'message_key' => $key, 'message_params' => $params];
    }

    /** Extracts one JSON object from a model reply, tolerating a code fence. */
    private static function decodeObject(string $reply): array
    {
        $text = trim($reply);
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
            $text = trim($m[1]);
        }
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        // Turkish dotless i and the apostrophes used in "202'ye" vanish into
        // spaces here so the same pattern matches both languages.
        return str_replace(["’", "'", "\u{2019}"], ' ', $text);
    }

    /**
     * Every number in the text, in the order it appears.
     *
     * Deliberately not bounded to the length of an extension: a forwarding
     * target may be an outside number, and "\b(\d{2,6})\b" cannot match one
     * at all (no word boundary sits inside "15551234567"), which silently
     * dropped those requests. The length is bounded by the validator instead,
     * where each field's own rule applies.
     */
    private static function numbersIn(string $text): array
    {
        $pattern = '/(?<!\d)(?:\+\s*)?(?:\(\d{1,5}\)|\d{1,20})(?:[\s\-\/.]+(?:\(\d{1,5}\)|\d{1,20}))*(?!\d)/u';
        if (!preg_match_all($pattern, $text, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[0] as $raw) {
            $raw = trim($raw);
            // Keep two space-separated short numbers (e.g. "101 202") apart
            // unless they look like a formatted phone number (+, 0..., (), -, /, or 3+ groups).
            if (strpos($raw, ' ') !== false
                && !preg_match('/^[+0(]|[\-\/.]/', $raw)
                && count(preg_split('/\s+/', $raw)) < 3) {
                foreach (preg_split('/\s+/', $raw) as $part) {
                    $clean = preg_replace('/[^0-9+]/', '', $part);
                    if (strlen(ltrim($clean, '+')) >= 2) {
                        $out[] = $clean;
                    }
                }
                continue;
            }
            $clean = preg_replace('/[^0-9+]/', '', $raw);
            if (strlen(ltrim($clean, '+')) >= 2) {
                $out[] = $clean;
            }
        }
        return $out;
    }

    private static function asExtension($value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return preg_match('/^\d{2,6}$/', $value) ? $value : null;
    }

    private static function asNumber($value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $digits = preg_replace('/[^0-9+]/', '', trim($value));
        return (strlen($digits) >= 3 && strlen($digits) <= 20) ? $digits : null;
    }

    private static function asBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return !in_array(mb_strtolower(trim($value)), ['0', 'no', 'false', 'off', 'kapali', 'kapalı'], true);
        }
        return (bool) $value;
    }

    /** Finds a group/queue the request names, by id or by title. */
    private static function matchNamed(string $text, array $rows): ?int
    {
        foreach ($rows as $row) {
            $title = mb_strtolower($row['title']);
            if ($title !== '' && mb_strpos($text, $title) !== false) {
                return (int) $row['id'];
            }
            if ((string) $row['id'] !== '' && preg_match('/\b' . preg_quote((string) $row['id'], '/') . '\b/', $text)) {
                return (int) $row['id'];
            }
        }
        return null;
    }

    /** A group/queue named directly (used by the model path, where it is an id). */
    private static function existsNamed($value, array $rows): ?int
    {
        $id = is_numeric($value) ? (int) $value : null;
        foreach ($rows as $row) {
            if ($id !== null && (int) $row['id'] === $id) {
                return $id;
            }
            if (is_string($value) && mb_strtolower(trim($value)) === mb_strtolower($row['title'])) {
                return (int) $row['id'];
            }
        }
        return null;
    }
}
