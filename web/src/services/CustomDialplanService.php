<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/repositories/CustomDialplanRepository.php';

/**
 * Business logic and validation for Custom Asterisk Dialplans.
 */
class CustomDialplanService
{
    /** Reserved context names that cannot be used as custom dialplan context names */
    public const RESERVED_CONTEXTS = [
        'general',
        'globals',
        'default',
        'from-internal-pbx',
        'from-internal-pbx-ortak',
        'from-internal-outbound',
        'from-internal-desktop',
        'from-internal-mobile',
        'from-internal-web',
        'from-trunk',
        'from-trunk-pbx',
        'from-trunk-fax',
        'outbound-fax',
        'internal-numbers-pbx',
        'featurecodes-pbx',
        'sub-callee-jb',
        'sub-status-prompt',
        'aipbx-lamp-toggle',
        'custom-dialplan-pbx',
        'cc-internal',
    ];

    /**
     * Return all custom dialplans ordered.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAll(): array
    {
        return CustomDialplanRepository::allOrdered();
    }

    /**
     * Return single custom dialplan by ID.
     *
     * @return array<string, mixed>|null
     */
    public static function getById(int $id): ?array
    {
        return CustomDialplanRepository::findById($id);
    }

    /**
     * Validate dialplan syntax and return any errors or warnings.
     *
     * @return array{valid: bool, errors: array<int, string>, warnings: array<int, string>}
     */
    public static function validateSyntax(string $content): array
    {
        $errors = [];
        $warnings = [];
        $trimmed = trim($content);

        if ($trimmed === '') {
            $errors[] = t('custom_dialplan.err_content_empty');
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        $lines = explode("\n", $content);
        foreach ($lines as $idx => $rawLine) {
            $lineNum = $idx + 1;
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }

            // Check bracket balance on this line
            $parenOpen = substr_count($line, '(');
            $parenClose = substr_count($line, ')');
            if ($parenOpen !== $parenClose) {
                $errors[] = sprintf(t('custom_dialplan.err_unbalanced_paren'), $lineNum);
            }

            $bracketOpen = substr_count($line, '[');
            $bracketClose = substr_count($line, ']');
            if ($bracketOpen !== $bracketClose) {
                $errors[] = sprintf(t('custom_dialplan.err_unbalanced_bracket'), $lineNum);
            }

            $varOpen = substr_count($line, '${');
            $braceClose = substr_count($line, '}');
            if ($varOpen > $braceClose) {
                $errors[] = sprintf(t('custom_dialplan.err_unbalanced_var'), $lineNum);
            }

            // Prevent reserved system [context] redefinition inside dialplan body
            if (preg_match('/^\[([a-zA-Z0-9_\-]+)\]$/', $line, $m)) {
                $inlineCtx = $m[1];
                if (in_array(strtolower($inlineCtx), self::RESERVED_CONTEXTS, true)) {
                    $errors[] = sprintf(t('custom_dialplan.err_context_reserved'), $inlineCtx);
                }
                continue;
            }

            // Check valid Asterisk statement start
            $isValidStatement = (bool)preg_match('/^(exten|same|include|ignorepat|switch)\s*=>/i', $line);

            if (!$isValidStatement) {
                $warnings[] = sprintf(t('custom_dialplan.warn_unknown_statement'), $lineNum, htmlspecialchars(mb_substr($line, 0, 40)));
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate data before save.
     *
     * @param array<string, mixed> $data
     * @return array<int, string> Error messages
     */
    public static function validateData(array $data, ?int $id = null): array
    {
        $errors = [];

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            $errors[] = t('custom_dialplan.err_title_required');
        } elseif (mb_strlen($title) > 100) {
            $errors[] = t('custom_dialplan.err_title_too_long');
        }

        $contextName = trim((string)($data['context_name'] ?? ''));
        if ($contextName === '') {
            $errors[] = t('custom_dialplan.err_context_required');
        } elseif (!preg_match('/^[a-zA-Z0-9_-]{2,80}$/', $contextName)) {
            $errors[] = t('custom_dialplan.err_context_invalid_format');
        } elseif (in_array(strtolower($contextName), self::RESERVED_CONTEXTS, true)) {
            $errors[] = sprintf(t('custom_dialplan.err_context_reserved'), $contextName);
        } else {
            $existing = CustomDialplanRepository::findByContextName($contextName);
            if ($existing && ($id === null || (int)$existing['id'] !== $id)) {
                $errors[] = sprintf(t('custom_dialplan.err_context_exists'), $contextName);
            }
        }

        $content = (string)($data['dialplan_content'] ?? '');
        $syntaxCheck = self::validateSyntax($content);
        if (!$syntaxCheck['valid']) {
            $errors = array_merge($errors, $syntaxCheck['errors']);
        }

        return $errors;
    }

    /**
     * Create or update a custom dialplan record.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, id?: int, errors?: array<int, string>, warnings?: array<int, string>}
     */
    public static function save(array $data, ?int $userId = null): array
    {
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $errors = self::validateData($data, $id);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $syntaxCheck = self::validateSyntax((string)($data['dialplan_content'] ?? ''));
        $title = trim((string)$data['title']);

        if ($id !== null) {
            $ok = CustomDialplanRepository::update($id, $data);
            if (!$ok) {
                return ['success' => false, 'errors' => [t('custom_dialplan.err_save_failed')]];
            }
            if (function_exists('markPendingSync')) {
                markPendingSync('custom_dialplan', 'custom_dialplan', $id, $title, 'update', $userId);
            }
            return ['success' => true, 'id' => $id, 'warnings' => $syntaxCheck['warnings']];
        }

        $newId = CustomDialplanRepository::create($data);
        if ($newId <= 0) {
            return ['success' => false, 'errors' => [t('custom_dialplan.err_save_failed')]];
        }
        if (function_exists('markPendingSync')) {
            markPendingSync('custom_dialplan', 'custom_dialplan', $newId, $title, 'create', $userId);
        }
        return ['success' => true, 'id' => $newId, 'warnings' => $syntaxCheck['warnings']];
    }

    /**
     * Delete a custom dialplan.
     */
    public static function delete(int $id, ?int $userId = null): bool
    {
        $record = CustomDialplanRepository::findById($id);
        if (!$record) {
            return false;
        }

        $title = (string)$record['title'];
        $ok = CustomDialplanRepository::delete($id);
        if ($ok && function_exists('markPendingSync')) {
            markPendingSync('custom_dialplan', 'custom_dialplan', $id, $title, 'delete', $userId);
        }
        return $ok;
    }

    /**
     * Toggle active state.
     */
    public static function toggleActive(int $id, ?int $userId = null): bool
    {
        $record = CustomDialplanRepository::findById($id);
        if (!$record) {
            return false;
        }

        $title = (string)$record['title'];
        $ok = CustomDialplanRepository::toggleActive($id);
        if ($ok && function_exists('markPendingSync')) {
            markPendingSync('custom_dialplan', 'custom_dialplan', $id, $title, 'update', $userId);
        }
        return $ok;
    }

    /**
     * Built-in Dialplan Templates library.
     *
     * @return array<string, array{title: string, context_name: string, description: string, include_in_internal: int, content: string}>
     */
    public static function getTemplates(): array
    {
        return [
            'intercom' => [
                'title' => t('custom_dialplan.tpl_intercom_title'),
                'context_name' => 'custom-intercom',
                'description' => t('custom_dialplan.tpl_intercom_desc'),
                'include_in_internal' => 1,
                'content' => "; " . t('custom_dialplan.tpl_intercom_title') . "\n"
                    . "exten => _*80.,1,NoOp(Intercom Auto-Answer to \${EXTEN:3} by \${CALLERID(num)})\n"
                    . " same => n,Set(TARGET_EXT=\${FILTER(0123456789,\${EXTEN:3})})\n"
                    . " same => n,GotoIf(\$[\"\${TARGET_EXT}\" = \"\"]?empty)\n"
                    . " same => n,Set(PJSIP_HEADER(add,Alert-Info)=<http://www.google.com>;info=alert-autoanswer;delay=0)\n"
                    . " same => n,Set(PJSIP_HEADER(add,Call-Info)=<http://www.google.com>;answer-after=0)\n"
                    . " same => n,Dial(PJSIP/\${TARGET_EXT}-sip&PJSIP/\${TARGET_EXT}-webrtc,20)\n"
                    . " same => n,Hangup()\n"
                    . " same => n(empty),Playback(beep)\n"
                    . " same => n,Hangup()\n",
            ],
            'spy_directed' => [
                'title' => t('custom_dialplan.tpl_spy_title'),
                'context_name' => 'custom-spy-coaching',
                'description' => t('custom_dialplan.tpl_spy_desc'),
                'include_in_internal' => 1,
                'content' => "; " . t('custom_dialplan.tpl_spy_title') . "\n"
                    . "exten => _*55.,1,NoOp(Supervisor Direct Listen on Extension \${EXTEN:3})\n"
                    . " same => n,Answer()\n"
                    . " same => n,ChanSpy(PJSIP/\${FILTER(0123456789,\${EXTEN:3})}-,b)\n"
                    . " same => n,Hangup()\n\n"
                    . "exten => _*56.,1,NoOp(Supervisor Direct Whisper on Extension \${EXTEN:3})\n"
                    . " same => n,Answer()\n"
                    . " same => n,ChanSpy(PJSIP/\${FILTER(0123456789,\${EXTEN:3})}-,qwb)\n"
                    . " same => n,Hangup()\n",
            ],
            'paging_group' => [
                'title' => t('custom_dialplan.tpl_paging_title'),
                'context_name' => 'custom-paging-broadcast',
                'description' => t('custom_dialplan.tpl_paging_desc'),
                'include_in_internal' => 1,
                'content' => "; " . t('custom_dialplan.tpl_paging_title') . "\n"
                    . "exten => *700,1,NoOp(Company-Wide Audio Announcement Paging)\n"
                    . " same => n,Answer()\n"
                    . " same => n,Playback(beep)\n"
                    . " same => n,Page(PJSIP/1001-sip&PJSIP/1002-sip&PJSIP/1003-sip,d,30)\n"
                    . " same => n,Hangup()\n",
            ],
            'crm_webhook' => [
                'title' => t('custom_dialplan.tpl_crm_title'),
                'context_name' => 'custom-crm-webhook',
                'description' => t('custom_dialplan.tpl_crm_desc'),
                'include_in_internal' => 0,
                'content' => "; " . t('custom_dialplan.tpl_crm_title') . "\n"
                    . "exten => s,1,NoOp(CRM Webhook Trigger for Caller \${CALLERID(num)})\n"
                    . " same => n,Set(SAFE_CALLER=\${FILTER(0123456789+,\${CALLERID(num)})})\n"
                    . " same => n,Set(SAFE_DID=\${FILTER(0123456789+,\${CDR(did)})})\n"
                    . " same => n,System(curl -s -X POST -d \"caller=\${SAFE_CALLER}&did=\${SAFE_DID}\" http://127.0.0.1:8080/api/call-notify &)\n"
                    . " same => n,Return()\n",
            ],
            'vip_inbound' => [
                'title' => t('custom_dialplan.tpl_vip_title'),
                'context_name' => 'custom-vip-routing',
                'description' => t('custom_dialplan.tpl_vip_desc'),
                'include_in_internal' => 0,
                'content' => "; " . t('custom_dialplan.tpl_vip_title') . "\n"
                    . "exten => s,1,NoOp(Checking VIP Status for \${CALLERID(num)})\n"
                    . " same => n,GotoIf(\$[\"\${CALLERID(num):-4}\" = \"9999\"]?vip_user)\n"
                    . " same => n,Return()\n"
                    . " same => n(vip_user),Set(CALLERID(name)=VIP-\${CALLERID(name)})\n"
                    . " same => n,Set(__VIP_CALL=1)\n"
                    . " same => n,Return()\n",
            ],
            'disa_secure' => [
                'title' => t('custom_dialplan.tpl_disa_title'),
                'context_name' => 'custom-disa',
                'description' => t('custom_dialplan.tpl_disa_desc'),
                'include_in_internal' => 1,
                'content' => "; " . t('custom_dialplan.tpl_disa_title') . "\n"
                    . "exten => 7777,1,NoOp(Incoming DISA access request)\n"
                    . " same => n,Answer()\n"
                    . " same => n,Authenticate(1234)\n"
                    . " same => n,DISA(no-password,from-internal-pbx)\n"
                    . " same => n,Hangup()\n",
            ],
        ];
    }
}
