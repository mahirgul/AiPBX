<?php

declare(strict_types=1);

require_once __DIR__ . '/../PhonebookService.php';
require_once __DIR__ . '/../MailTemplateService.php';
require_once __DIR__ . '/../VoicemailService.php';
require_once __DIR__ . '/../../queue_helper.php';
require_once __DIR__ . '/../../repositories/MyPhoneRepository.php';
require_once __DIR__ . '/ReminderService.php';

/**
 * Single source of truth for PBX tools exposed to AI assistants (local LLMs,
 * cloud tool-calling, OpenAI Realtime, and external MCP/HTTP agents).
 */
final class PbxToolRegistry
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            [
                'name' => 'find_person',
                'description' => 'Find an internal extension or phonebook contact by name, role, or number.',
                'kind' => 'lookup',
                'confirm' => false,
                'permission' => 'everyone',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Person name, role, or phone/extension number'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'extension_state',
                'description' => 'Check whether an extension is available, on DND, forwarded, or offline.',
                'kind' => 'lookup',
                'confirm' => false,
                'permission' => 'everyone',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extension' => ['type' => 'string', 'description' => 'Internal extension number'],
                    ],
                    'required' => ['extension'],
                ],
            ],
            [
                'name' => 'transfer_call',
                'description' => 'Transfer the caller to an extension or target, optionally with a spoken announcement.',
                'kind' => 'action',
                'confirm' => false,
                'permission' => 'everyone',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'target' => ['type' => 'string', 'description' => 'Target extension or destination id'],
                        'announce' => ['type' => 'string', 'description' => 'Optional announcement text for warm transfer'],
                    ],
                    'required' => ['target'],
                ],
            ],
            [
                'name' => 'leave_message',
                'description' => 'Leave a written note or message for a person when they cannot take the call (requires caller confirmation).',
                'kind' => 'action',
                'confirm' => true,
                'permission' => 'everyone',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'recipient' => ['type' => 'string', 'description' => 'Recipient extension or full name'],
                        'message' => ['type' => 'string', 'description' => 'Message text from the caller'],
                        'confirmed' => ['type' => 'boolean', 'description' => 'True only after the caller verbally confirmed'],
                    ],
                    'required' => ['recipient', 'message', 'confirmed'],
                ],
            ],
            [
                'name' => 'reminder_create',
                'description' => 'Schedule a reminder call at a specific date/time for an extension (requires caller confirmation).',
                'kind' => 'action',
                'confirm' => true,
                'permission' => 'self',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extension' => ['type' => 'string', 'description' => 'Target extension number'],
                        'remind_at' => ['type' => 'string', 'description' => 'ISO date/time e.g. 2026-11-22 09:00'],
                        'message' => ['type' => 'string', 'description' => 'Reminder message'],
                        'recurrence' => ['type' => 'string', 'enum' => ['none', 'daily', 'weekdays']],
                        'confirmed' => ['type' => 'boolean', 'description' => 'True only after caller confirmation'],
                    ],
                    'required' => ['extension', 'remind_at', 'message', 'confirmed'],
                ],
            ],
            [
                'name' => 'wakeup_call',
                'description' => 'Schedule a wake-up call for an extension (requires caller confirmation).',
                'kind' => 'action',
                'confirm' => true,
                'permission' => 'self',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extension' => ['type' => 'string', 'description' => 'Target extension number'],
                        'remind_at' => ['type' => 'string', 'description' => 'ISO date/time e.g. 2026-11-22 07:30'],
                        'recurrence' => ['type' => 'string', 'enum' => ['none', 'daily', 'weekdays']],
                        'confirmed' => ['type' => 'boolean', 'description' => 'True only after caller confirmation'],
                    ],
                    'required' => ['extension', 'remind_at', 'confirmed'],
                ],
            ],
            [
                'name' => 'reminder_list',
                'description' => 'List upcoming pending reminders and wake-up calls for an extension.',
                'kind' => 'lookup',
                'confirm' => false,
                'permission' => 'self',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extension' => ['type' => 'string', 'description' => 'Extension number'],
                    ],
                    'required' => ['extension'],
                ],
            ],
            [
                'name' => 'reminder_cancel',
                'description' => 'Cancel a pending reminder or wake-up call by its ID (requires caller confirmation).',
                'kind' => 'action',
                'confirm' => true,
                'permission' => 'self',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Reminder ID'],
                        'confirmed' => ['type' => 'boolean', 'description' => 'True only after caller confirmation'],
                    ],
                    'required' => ['id', 'confirmed'],
                ],
            ],
            [
                'name' => 'set_dnd',
                'description' => 'Enable or disable Do Not Disturb (DND) on an extension (requires caller confirmation).',
                'kind' => 'action',
                'confirm' => true,
                'permission' => 'self',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extension' => ['type' => 'string', 'description' => 'Extension number'],
                        'enabled' => ['type' => 'boolean', 'description' => 'True to enable DND, false to disable'],
                        'confirmed' => ['type' => 'boolean', 'description' => 'True only after caller confirmation'],
                    ],
                    'required' => ['extension', 'enabled', 'confirmed'],
                ],
            ],
            [
                'name' => 'set_forward',
                'description' => 'Set or clear unconditional call forwarding on an extension (requires caller confirmation).',
                'kind' => 'action',
                'confirm' => true,
                'permission' => 'self',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extension' => ['type' => 'string', 'description' => 'Extension number'],
                        'target' => ['type' => 'string', 'description' => 'Forward destination number, or empty string to cancel'],
                        'confirmed' => ['type' => 'boolean', 'description' => 'True only after caller confirmation'],
                    ],
                    'required' => ['extension', 'target', 'confirmed'],
                ],
            ],
            [
                'name' => 'queue_pause',
                'description' => 'Pause or unpause an agent in queues (requires caller confirmation).',
                'kind' => 'action',
                'confirm' => true,
                'permission' => 'self',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extension' => ['type' => 'string', 'description' => 'Agent extension number'],
                        'paused' => ['type' => 'boolean', 'description' => 'True to pause, false to unpause'],
                        'reason' => ['type' => 'string', 'description' => 'Optional break reason'],
                        'confirmed' => ['type' => 'boolean', 'description' => 'True only after caller confirmation'],
                    ],
                    'required' => ['extension', 'paused', 'confirmed'],
                ],
            ],
            [
                'name' => 'my_missed_calls',
                'description' => 'List recent missed calls for an extension.',
                'kind' => 'lookup',
                'confirm' => false,
                'permission' => 'self',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extension' => ['type' => 'string', 'description' => 'Extension number'],
                    ],
                    'required' => ['extension'],
                ],
            ],
            [
                'name' => 'my_voicemails',
                'description' => 'List voicemail messages and transcripts for an extension.',
                'kind' => 'lookup',
                'confirm' => false,
                'permission' => 'self',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extension' => ['type' => 'string', 'description' => 'Extension number'],
                    ],
                    'required' => ['extension'],
                ],
            ],
            [
                'name' => 'send_dtmf',
                'description' => 'Send DTMF keypad digits (0-9, *, #, or w for a 0.5s pause) on the active call channel (for example to dial a sub-extension or choose an IVR menu option).',
                'kind' => 'action',
                'confirm' => false,
                'permission' => 'everyone',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'digits' => ['type' => 'string', 'description' => 'DTMF digits to press (0-9, *, #, w)'],
                        'pause_ms' => ['type' => 'integer', 'description' => 'Pause between tones in milliseconds (default 100)'],
                    ],
                    'required' => ['digits'],
                ],
            ],
            [
                'name' => 'collect_dtmf',
                'description' => 'Wait for and read DTMF keypad digits pressed by the caller on their phone (for example TC ID, subscriber number, or menu choice).',
                'kind' => 'lookup',
                'confirm' => false,
                'permission' => 'everyone',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'max_digits' => ['type' => 'integer', 'description' => 'Maximum digits to collect (1-32, default 11)'],
                        'timeout_sec' => ['type' => 'integer', 'description' => 'Seconds to wait for input (2-20, default 8)'],
                        'terminator' => ['type' => 'string', 'description' => 'Optional terminating key such as #'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'dial_and_navigate',
                'description' => 'Dial an external or internal phone number, wait for answer, listen to any IVR/greeting, automatically press a sub-extension or menu digits via DTMF, and connect/transfer to the requester.',
                'kind' => 'action',
                'confirm' => false,
                'permission' => 'everyone',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'target_number' => ['type' => 'string', 'description' => 'Phone number to dial'],
                        'requester_ext' => ['type' => 'string', 'description' => 'Requester extension to bridge or call back (defaults to caller extension)'],
                        'target_dtmf' => ['type' => 'string', 'description' => 'Known sub-extension or DTMF sequence (e.g. 1000) to dial once answered'],
                        'goal' => ['type' => 'string', 'description' => 'Target department or person goal for IVR navigation (e.g. müşteri temsilcisi)'],
                        'mode' => ['type' => 'string', 'enum' => ['wait', 'callback'], 'description' => 'wait bridges immediately if requester stays on line; callback rings requester when representative answers'],
                    ],
                    'required' => ['target_number'],
                ],
            ],
        ];
    }

    /**
     * @param list<string> $enabledNames
     * @return list<array<string, mixed>>
     */
    public static function forEnabled(array $enabledNames): array
    {
        if ($enabledNames === []) {
            return [];
        }
        $set = array_flip($enabledNames);
        return array_values(array_filter(self::all(), static fn (array $t): bool => isset($set[$t['name']])));
    }

    public static function findDefinition(string $name): ?array
    {
        foreach (self::all() as $t) {
            if ($t['name'] === $name) {
                return $t;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $context
     * @return array{ok: bool, kind: string, text: string, action?: array<string, mixed>}
     */
    public static function execute(string $name, array $args, array $context = []): array
    {
        $def = self::findDefinition($name);
        if ($def === null) {
            return ['ok' => false, 'kind' => 'lookup', 'text' => 'Unknown tool'];
        }
        if (!empty($def['confirm']) && empty($args['confirmed'])) {
            return ['ok' => false, 'kind' => (string) $def['kind'], 'text' => 'Caller confirmation required before running this tool.'];
        }
        $callerExt = (string) preg_replace('/[^0-9]/', '', (string) ($context['caller'] ?? ''));
        if (($def['permission'] ?? '') === 'self') {
            if ($callerExt === '') {
                return ['ok' => false, 'kind' => (string) $def['kind'], 'text' => 'Permission denied: caller extension is required.'];
            }
            $argExt = isset($args['extension']) ? (string) preg_replace('/[^0-9]/', '', (string) $args['extension']) : '';
            if ($argExt !== '' && $argExt !== $callerExt) {
                return ['ok' => false, 'kind' => (string) $def['kind'], 'text' => 'Permission denied: you can only manage your own extension.'];
            }
        }

        return match ($name) {
            'find_person' => self::execFindPerson(trim((string) ($args['query'] ?? ''))),
            'extension_state' => self::execExtensionState(trim((string) ($args['extension'] ?? ''))),
            'transfer_call' => self::execTransferCall(
                trim((string) ($args['target'] ?? '')),
                trim((string) ($args['announce'] ?? ''))
            ),
            'leave_message' => self::execLeaveMessage(
                trim((string) ($args['recipient'] ?? '')),
                trim((string) ($args['message'] ?? '')),
                $context
            ),
            'reminder_create' => self::execReminderCreate($args, 'reminder'),
            'wakeup_call' => self::execReminderCreate($args, 'wakeup'),
            'reminder_list' => self::execReminderList(trim((string) ($args['extension'] ?? ($context['caller'] ?? '')))),
            'reminder_cancel' => self::execReminderCancel((int) ($args['id'] ?? 0), $callerExt),
            'set_dnd' => self::execSetDnd(trim((string) ($args['extension'] ?? ($context['caller'] ?? ''))), !empty($args['enabled'])),
            'set_forward' => self::execSetForward(trim((string) ($args['extension'] ?? ($context['caller'] ?? ''))), trim((string) ($args['target'] ?? ''))),
            'queue_pause' => self::execQueuePause(trim((string) ($args['extension'] ?? ($context['caller'] ?? ''))), !empty($args['paused']), trim((string) ($args['reason'] ?? 'Break'))),
            'my_missed_calls' => self::execMyMissedCalls(trim((string) ($args['extension'] ?? ($context['caller'] ?? '')))),
            'my_voicemails' => self::execMyVoicemails(trim((string) ($args['extension'] ?? ($context['caller'] ?? '')))),
            'send_dtmf' => self::execSendDtmf($args, $context),
            'collect_dtmf' => self::execCollectDtmf($args),
            'dial_and_navigate' => self::execDialAndNavigate($args, $context),
            default => ['ok' => false, 'kind' => 'lookup', 'text' => 'Unsupported tool'],
        };
    }

    /** @return array{ok: bool, kind: string, text: string} */
    private static function execFindPerson(string $query): array
    {
        if ($query === '') {
            return ['ok' => false, 'kind' => 'lookup', 'text' => 'Missing search query.'];
        }
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $st = getDB()->prepare(
            "SELECT extension, full_name, role FROM sys_users
             WHERE is_active = 1 AND extension IS NOT NULL AND extension <> ''
               AND (full_name LIKE ? OR extension LIKE ? OR role LIKE ?)
             ORDER BY full_name ASC LIMIT 5"
        );
        $st->execute([$like, $like, $like]);
        $lines = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
            $lines[] = sprintf('%s (ext %s, %s)', $u['full_name'], $u['extension'], $u['role']);
        }
        [$contacts] = PhonebookService::search($query, 5, 0);
        foreach ($contacts as $c) {
            $num = $c['phone_work'] ?: ($c['phone_mobile'] ?: $c['phone_other']);
            if ($num !== '') {
                $company = trim((string) ($c['company'] ?? ''));
                $subExt = trim((string) ($c['sub_extension'] ?? ''));
                $head = $c['display_name'] . ($company !== '' && strcasecmp($company, (string) $c['display_name']) !== 0 ? " [{$company}]" : '');
                $tail = $subExt !== '' ? "{$num}, sub_extension/dtmf: {$subExt}" : $num;
                $lines[] = "{$head} ({$tail})";
            }
        }
        if ($lines === []) {
            return ['ok' => true, 'kind' => 'lookup', 'text' => 'No matching person found.'];
        }
        return ['ok' => true, 'kind' => 'lookup', 'text' => implode('; ', array_slice($lines, 0, 6))];
    }

    /** @return array{ok: bool, kind: string, text: string} */
    private static function execExtensionState(string $ext): array
    {
        $ext = (string) preg_replace('/[^0-9]/', '', $ext);
        if ($ext === '') {
            return ['ok' => false, 'kind' => 'lookup', 'text' => 'Invalid extension number.'];
        }
        $st = getDB()->prepare('SELECT full_name, is_active, dnd_enabled, call_forward_number FROM sys_users WHERE extension = ? LIMIT 1');
        $st->execute([$ext]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || empty($u['is_active'])) {
            return ['ok' => true, 'kind' => 'lookup', 'text' => "Extension {$ext} not found or inactive."];
        }
        if (!empty($u['dnd_enabled'])) {
            return ['ok' => true, 'kind' => 'lookup', 'text' => "{$u['full_name']} ({$ext}): DND enabled."];
        }
        if (!empty($u['call_forward_number'])) {
            return ['ok' => true, 'kind' => 'lookup', 'text' => "{$u['full_name']} ({$ext}): forwarded to {$u['call_forward_number']}."];
        }
        $status = 'available';
        if (class_exists('AsteriskHelper')) {
            try {
                $cli = AsteriskHelper::execCLI("pjsip show endpoint {$ext}");
                $out = is_array($cli) ? implode("\n", $cli['output'] ?? []) : (string) $cli;
                if (stripos($out, 'Unavailable') !== false) {
                    $status = 'offline';
                } elseif (stripos($out, 'InUse') !== false || stripos($out, 'Busy') !== false) {
                    $status = 'busy';
                }
            } catch (\Throwable $e) {
            }
        }
        return ['ok' => true, 'kind' => 'lookup', 'text' => "{$u['full_name']} ({$ext}): {$status}."];
    }

    /** @return array{ok: bool, kind: string, text: string, action?: array<string, mixed>} */
    private static function execTransferCall(string $target, string $announce): array
    {
        $clean = (string) preg_replace('/[^0-9A-Za-z_-]/', '', $target);
        if ($clean === '') {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Invalid transfer target.'];
        }
        $st = getDB()->prepare('SELECT extension, full_name FROM sys_users WHERE is_active = 1 AND extension = ? LIMIT 1');
        $st->execute([$clean]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            return [
                'ok' => true,
                'kind' => 'action',
                'text' => "Transferring to {$u['full_name']} ({$clean}).",
                'action' => [
                    'type' => 'transfer',
                    'target' => $clean,
                    'endpoint' => 'PJSIP/' . $clean,
                    'announce' => mb_substr($announce, 0, 200),
                ],
            ];
        }
        return [
            'ok' => true,
            'kind' => 'action',
            'text' => "Transferring to {$clean}.",
            'action' => ['type' => 'transfer', 'target' => $clean, 'endpoint' => '', 'announce' => mb_substr($announce, 0, 200)],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{ok: bool, kind: string, text: string}
     */
    private static function execLeaveMessage(string $recipient, string $message, array $context): array
    {
        if ($recipient === '' || $message === '') {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Recipient and message are required.'];
        }
        $like = '%' . addcslashes($recipient, '%_\\') . '%';
        $st = getDB()->prepare('SELECT id, extension, full_name, email FROM sys_users WHERE is_active = 1 AND (extension = ? OR full_name LIKE ?) LIMIT 1');
        $st->execute([$recipient, $like]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        $targetLabel = $u ? ($u['full_name'] . ' (' . $u['extension'] . ')') : $recipient;

        $appId = max(1, (int) ($context['app_id'] ?? 1));
        $uuid = preg_replace('/[^0-9a-f-]/i', '', (string) ($context['call_uuid'] ?? '')) ?: ('msg-' . bin2hex(random_bytes(8)));
        $caller = mb_substr((string) ($context['caller'] ?? ''), 0, 40);
        $callerName = mb_substr((string) ($context['caller_name'] ?? ''), 0, 80);

        getDB()->prepare(
            'INSERT IGNORE INTO ai_requests (app_id, call_uuid, caller, caller_name, intent_id, intent_name, transcript, score)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $appId,
            substr($uuid, 0, 36),
            $caller,
            $callerName,
            'leave_message',
            mb_substr('Message: ' . $targetLabel, 0, 100),
            mb_substr($message, 0, 2000),
            1.0,
        ]);

        if ($u && !empty($u['email'])) {
            try {
                $lang = MailTemplateService::systemLanguage();
                MailTemplateService::send('ai_request', (string) $u['email'], $lang, [
                    'request' => 'Message for ' . $targetLabel,
                    'caller' => trim($callerName . ' ' . $caller),
                    'caller_name' => $callerName,
                    'caller_number' => $caller,
                    'transcript' => $message,
                    'app' => 'AI Assistant',
                    'date' => date('d.m.Y H:i'),
                    'portal_link' => '/ai-agent',
                ]);
            } catch (\Throwable $e) {
            }
        }
        return ['ok' => true, 'kind' => 'action', 'text' => "Message saved for {$targetLabel}."];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{ok: bool, kind: string, text: string}
     */
    private static function execReminderCreate(array $args, string $kind): array
    {
        $res = ReminderService::create([
            'extension' => (string) ($args['extension'] ?? ''),
            'kind' => $kind,
            'remind_at' => (string) ($args['remind_at'] ?? ''),
            'message' => (string) ($args['message'] ?? ''),
            'recurrence' => (string) ($args['recurrence'] ?? 'none'),
        ]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'kind' => 'action', 'text' => (string) ($res['error'] ?? 'Could not create reminder.')];
        }
        return ['ok' => true, 'kind' => 'action', 'text' => sprintf('Scheduled %s #%d for %s.', $kind, (int) ($res['id'] ?? 0), (string) ($args['remind_at'] ?? ''))];
    }

    /** @return array{ok: bool, kind: string, text: string} */
    private static function execReminderList(string $ext): array
    {
        $ext = (string) preg_replace('/[^0-9]/', '', $ext);
        if ($ext === '') {
            return ['ok' => false, 'kind' => 'lookup', 'text' => 'Missing extension.'];
        }
        $rows = array_values(array_filter(
            ReminderService::listForUser(null, true, $ext),
            static fn (array $r): bool => $r['extension'] === $ext && $r['status'] === 'pending'
        ));
        if ($rows === []) {
            return ['ok' => true, 'kind' => 'lookup', 'text' => "No pending reminders for extension {$ext}."];
        }
        $lines = array_map(
            static fn (array $r): string => sprintf('#%d %s at %s: %s', (int) $r['id'], $r['kind'], $r['remind_at'], $r['message']),
            array_slice($rows, 0, 5)
        );
        return ['ok' => true, 'kind' => 'lookup', 'text' => implode('; ', $lines)];
    }

    /** @return array{ok: bool, kind: string, text: string} */
    private static function execReminderCancel(int $id, string $callerExt = ''): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Pending reminder not found.'];
        }
        if ($callerExt === '') {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Permission denied: caller extension is required.'];
        }
        $st = getDB()->prepare('SELECT extension FROM ai_reminders WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $ownerExt = (string) ($st->fetchColumn() ?: '');
        if ($ownerExt !== '' && $ownerExt !== $callerExt) {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Permission denied: you can only cancel your own reminders.'];
        }
        if (!ReminderService::cancel($id, null, true)) {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Pending reminder not found.'];
        }
        return ['ok' => true, 'kind' => 'action', 'text' => "Reminder #{$id} cancelled."];
    }

    /** @return array{ok: bool, kind: string, text: string} */
    private static function execSetDnd(string $ext, bool $enabled): array
    {
        $ext = (string) preg_replace('/[^0-9]/', '', $ext);
        if ($ext === '') {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Invalid extension.'];
        }
        $st = getDB()->prepare('UPDATE sys_users SET dnd_enabled = ? WHERE extension = ? AND is_active = 1');
        $st->execute([$enabled ? 1 : 0, $ext]);
        if ($st->rowCount() === 0) {
            return ['ok' => false, 'kind' => 'action', 'text' => "Extension {$ext} not found."];
        }
        if (function_exists('markPendingSync')) {
            markPendingSync('extensions', 'extension', $ext, 'AI Tool: DND ' . ($enabled ? 'on' : 'off'), 'update', null);
        }
        return ['ok' => true, 'kind' => 'action', 'text' => sprintf('DND %s for extension %s.', $enabled ? 'enabled' : 'disabled', $ext)];
    }

    /** @return array{ok: bool, kind: string, text: string} */
    private static function execSetForward(string $ext, string $target): array
    {
        $ext = (string) preg_replace('/[^0-9]/', '', $ext);
        $cleanTarget = (string) preg_replace('/[^0-9+*#]/', '', $target);
        if ($ext === '') {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Invalid extension.'];
        }
        $st = getDB()->prepare('UPDATE sys_users SET call_forward_number = ? WHERE extension = ? AND is_active = 1');
        $st->execute([$cleanTarget !== '' ? $cleanTarget : null, $ext]);
        if ($st->rowCount() === 0) {
            return ['ok' => false, 'kind' => 'action', 'text' => "Extension {$ext} not found."];
        }
        if (function_exists('markPendingSync')) {
            markPendingSync('extensions', 'extension', $ext, 'AI Tool: call forward ' . ($cleanTarget ?: 'cleared'), 'update', null);
        }
        return ['ok' => true, 'kind' => 'action', 'text' => $cleanTarget !== ''
            ? "Call forwarding for {$ext} set to {$cleanTarget}."
            : "Call forwarding cleared for {$ext}."];
    }

    /** @return array{ok: bool, kind: string, text: string} */
    private static function execQueuePause(string $ext, bool $paused, string $reason): array
    {
        $ext = (string) preg_replace('/[^0-9]/', '', $ext);
        if ($ext === '') {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Invalid extension.'];
        }
        if ($paused) {
            QueueHelper::pauseMember($ext, $reason !== '' ? $reason : 'Break');
            return ['ok' => true, 'kind' => 'action', 'text' => "Extension {$ext} paused ({$reason})."];
        }
        QueueHelper::unpauseMember($ext);
        return ['ok' => true, 'kind' => 'action', 'text' => "Extension {$ext} unpaused."];
    }

    /** @return array{ok: bool, kind: string, text: string} */
    private static function execMyMissedCalls(string $ext): array
    {
        $ext = (string) preg_replace('/[^0-9]/', '', $ext);
        if ($ext === '') {
            return ['ok' => false, 'kind' => 'lookup', 'text' => 'Invalid extension.'];
        }
        $rows = MyPhoneRepository::getRecentCalls($ext, 'missed', null, 5);
        if ($rows === []) {
            return ['ok' => true, 'kind' => 'lookup', 'text' => "No recent missed calls for {$ext}."];
        }
        $lines = array_map(
            static fn (array $r): string => trim((string) ($r['caller_id_name'] ?? '') . ' ' . ($r['src'] ?? $r['other_party'] ?? '')) . ' at ' . ($r['calldate'] ?? ''),
            array_slice($rows, 0, 5)
        );
        return ['ok' => true, 'kind' => 'lookup', 'text' => implode('; ', $lines)];
    }

    /** @return array{ok: bool, kind: string, text: string} */
    private static function execMyVoicemails(string $ext): array
    {
        $ext = (string) preg_replace('/[^0-9]/', '', $ext);
        if ($ext === '') {
            return ['ok' => false, 'kind' => 'lookup', 'text' => 'Invalid extension.'];
        }
        $msgs = VoicemailService::getVoicemailMessages($ext);
        if ($msgs === []) {
            return ['ok' => true, 'kind' => 'lookup', 'text' => "No voicemails for {$ext}."];
        }
        $lines = [];
        foreach (array_slice($msgs, 0, 5) as $m) {
            $from = (string) ($m['callerid'] ?? 'Unknown');
            $txt = trim((string) ($m['transcript'] ?? ''));
            $lines[] = $txt !== '' ? "{$from}: {$txt}" : "{$from} ({$m['duration']}s)";
        }
        return ['ok' => true, 'kind' => 'lookup', 'text' => sprintf('%d voicemail(s): %s', count($msgs), implode('; ', $lines))];
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $context
     * @return array{ok: bool, kind: string, text: string}
     */
    private static function execSendDtmf(array $args, array $context): array
    {
        $digits = (string) preg_replace('/[^0-9*#ABCDwW]/', '', (string) ($args['digits'] ?? ''));
        if ($digits === '' || strlen($digits) > 32) {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Invalid DTMF digits.'];
        }
        $callUuid = trim((string) ($context['call_uuid'] ?? ''));
        if ($callUuid === '') {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Missing active call UUID.'];
        }
        $pauseMs = max(50, min(500, (int) ($args['pause_ms'] ?? 100)));
        $res = self::postLocalAiService('/v1/ari/dtmf', [
            'call_uuid' => $callUuid,
            'digits' => $digits,
            'between' => $pauseMs,
            'duration' => 100,
        ]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'kind' => 'action', 'text' => (string) ($res['error'] ?? 'Could not send DTMF.')];
        }
        return ['ok' => true, 'kind' => 'action', 'text' => "Sent DTMF digits: {$digits}"];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{ok: bool, kind: string, text: string}
     */
    private static function execCollectDtmf(array $args): array
    {
        $maxDigits = max(1, min(32, (int) ($args['max_digits'] ?? 11)));
        $timeoutSec = max(2, min(20, (int) ($args['timeout_sec'] ?? 8)));
        $terminator = substr(preg_replace('/[^0-9*#]/', '', (string) ($args['terminator'] ?? '#')) ?? '#', 0, 1);
        return [
            'ok' => true,
            'kind' => 'lookup',
            'text' => "DTMF collector ready (max_digits={$maxDigits}, timeout={$timeoutSec}s, terminator={$terminator}). Live calls handle this on AudioSocket.",
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $context
     * @return array{ok: bool, kind: string, text: string, action?: array<string, mixed>}
     */
    private static function execDialAndNavigate(array $args, array $context): array
    {
        $target = (string) preg_replace('/[^0-9+*#]/', '', (string) ($args['target_number'] ?? ''));
        if (strlen($target) < 2 || strlen($target) > 24) {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Invalid target_number.'];
        }
        $requesterExt = (string) preg_replace('/[^0-9]/', '', (string) ($args['requester_ext'] ?? ($context['caller'] ?? '')));
        if ($requesterExt === '' || strlen($requesterExt) > 10) {
            return ['ok' => false, 'kind' => 'action', 'text' => 'Valid requester_ext is required to transfer or call back.'];
        }
        $targetDtmf = (string) preg_replace('/[^0-9*#ABCDwW,]/', '', (string) ($args['target_dtmf'] ?? ''));
        $goal = trim(mb_substr((string) ($args['goal'] ?? ''), 0, 200));
        $mode = in_array((string) ($args['mode'] ?? ''), ['wait', 'callback'], true) ? (string) $args['mode'] : 'callback';
        $payload = [
            'target_number' => $target,
            'requester_ext' => $requesterExt,
            'target_dtmf' => $targetDtmf,
            'goal' => $goal,
            'mode' => $mode,
            'app_id' => max(0, (int) ($context['app_id'] ?? 0)),
            'call_uuid' => trim((string) ($context['call_uuid'] ?? '')),
        ];
        $res = self::postLocalAiService('/v1/outbound/navigate', $payload, 6.0);
        if (empty($res['ok'])) {
            return ['ok' => false, 'kind' => 'action', 'text' => (string) ($res['error'] ?? 'Could not start outbound navigation call.')];
        }
        $jobId = (string) ($res['job_id'] ?? '');
        $msg = (string) ($res['message'] ?? "Dialing {$target} and navigating for {$requesterExt} (job {$jobId}, mode={$mode}).");
        return ['ok' => true, 'kind' => 'action', 'text' => $msg];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function postLocalAiService(string $path, array $payload, float $timeout = 3.0): array
    {
        require_once __DIR__ . '/AiAppService.php';
        $key = AiAppService::dialplanKey();
        if ($key === '') {
            return ['ok' => false, 'error' => 'Local AI service key not configured.'];
        }
        $url = 'http://127.0.0.1:8790' . $path . '?key=' . rawurlencode($key);
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Failed to initialize request.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => max(1, (int) ceil($timeout)),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if (!is_string($raw) || $raw === '' || $code >= 400) {
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $msg = is_array($decoded) && !empty($decoded['error']) ? (string) $decoded['error'] : ($err ?: "HTTP {$code}");
            return ['ok' => false, 'error' => $msg];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Invalid response'];
    }
}
