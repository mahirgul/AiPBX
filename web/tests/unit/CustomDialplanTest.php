<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/core/BaseRepository.php';
require_once dirname(__DIR__, 2) . '/src/repositories/CustomDialplanRepository.php';
require_once dirname(__DIR__, 2) . '/src/services/CustomDialplanService.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncCustomDialplan.php';

final class CustomDialplanTest extends TestCase
{
    private array $createdIds = [];

    protected function setUp(): void
    {
        if (defined('DB_NAME') && DB_NAME !== 'asterisk_test') {
            $this->fail('Tests must run only on asterisk_test');
        }
    }

    /** A row from a restored backup or SQL never went through the portal's check. */
    public function testAFileDirectiveOrReservedNameIsSkippedWhenWriting(): void
    {
        $conf = buildCustomDialplanConf([
            ['title' => 'a', 'context_name' => 'zz-good', 'dialplan_content' => 'exten => s,1,NoOp(good)'],
            ['title' => 'b', 'context_name' => 'zz-exec', 'dialplan_content' => "exten => s,1,NoOp()\n#exec /bin/evil"],
            ['title' => 'c', 'context_name' => 'zz-inc', 'dialplan_content' => '  # include /etc/passwd'],
            ['title' => 'd', 'context_name' => 'general', 'dialplan_content' => 'exten => s,1,NoOp(res)'],
        ]);
        $this->assertStringContainsString('NoOp(good)', $conf);
        $this->assertStringNotContainsString('/bin/evil', $conf);
        $this->assertStringNotContainsString('/etc/passwd', $conf);
        $this->assertStringNotContainsString('NoOp(res)', $conf);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            CustomDialplanRepository::delete($id);
        }
        $this->createdIds = [];
    }

    public function testRepositoryCrud(): void
    {
        $ctxName = 'test-intercom-' . bin2hex(random_bytes(4));
        $id = CustomDialplanRepository::create([
            'title' => 'Test Intercom Feature',
            'context_name' => $ctxName,
            'dialplan_content' => "exten => *80,1,NoOp(Test Intercom)\n same => n,Hangup()\n",
            'include_in_internal' => 1,
            'description' => 'Unit test context',
            'sort_order' => 5,
            'is_active' => 1,
        ]);

        $this->assertGreaterThan(0, $id);
        $this->createdIds[] = $id;

        $entry = CustomDialplanRepository::findById($id);
        $this->assertNotNull($entry);
        $this->assertSame('Test Intercom Feature', $entry['title']);
        $this->assertSame($ctxName, $entry['context_name']);
        $this->assertSame(1, (int)$entry['include_in_internal']);
        $this->assertSame(1, (int)$entry['is_active']);

        $byContext = CustomDialplanRepository::findByContextName($ctxName);
        $this->assertNotNull($byContext);
        $this->assertSame($id, (int)$byContext['id']);

        // Update
        $updated = CustomDialplanRepository::update($id, [
            'title' => 'Updated Intercom Feature',
            'context_name' => $ctxName,
            'dialplan_content' => "exten => *80,1,NoOp(Updated)\n same => n,Hangup()\n",
            'include_in_internal' => 0,
            'description' => 'Updated desc',
            'sort_order' => 15,
            'is_active' => 1,
        ]);
        $this->assertTrue($updated);

        $fresh = CustomDialplanRepository::findById($id);
        $this->assertSame('Updated Intercom Feature', $fresh['title']);
        $this->assertSame(0, (int)$fresh['include_in_internal']);
        $this->assertSame(15, (int)$fresh['sort_order']);

        // Toggle
        CustomDialplanRepository::toggleActive($id);
        $toggled = CustomDialplanRepository::findById($id);
        $this->assertSame(0, (int)$toggled['is_active']);

        // Delete
        $del = CustomDialplanRepository::delete($id);
        $this->assertTrue($del);
        $this->assertNull(CustomDialplanRepository::findById($id));
    }

    public function testSyntaxValidator(): void
    {
        // Valid snippet
        $validCode = "; Intercom code\nexten => *80,1,NoOp(Valid Call)\n same => n,Dial(PJSIP/\${EXTEN}-sip,20)\n same => n,Hangup()\n";
        $res = CustomDialplanService::validateSyntax($validCode);
        $this->assertTrue($res['valid']);
        $this->assertEmpty($res['errors']);

        // Empty content
        $emptyRes = CustomDialplanService::validateSyntax("   \n\n  ");
        $this->assertFalse($emptyRes['valid']);
        $this->assertNotEmpty($emptyRes['errors']);

        // Unbalanced parentheses
        $brokenParen = "exten => *80,1,NoOp(Broken paren\n same => n,Hangup()\n";
        $resParen = CustomDialplanService::validateSyntax($brokenParen);
        $this->assertFalse($resParen['valid']);
        $this->assertStringContainsString('Line 1', $resParen['errors'][0]);

        // Unbalanced variable brace
        $brokenVar = "exten => *80,1,Set(X=\${EXTEN:1)\n same => n,Hangup()\n";
        $resVar = CustomDialplanService::validateSyntax($brokenVar);
        $this->assertFalse($resVar['valid']);
        $this->assertStringContainsString('Line 1', $resVar['errors'][0]);

        // Warning on unknown statement
        $unknownStmt = "exten => s,1,NoOp(Start)\n random_gibberish_statement\n same => n,Hangup()\n";
        $resWarn = CustomDialplanService::validateSyntax($unknownStmt);
        $this->assertTrue($resWarn['valid']); // Warning does not block validity
        $this->assertNotEmpty($resWarn['warnings']);
    }

    public function testValidationRules(): void
    {
        // Missing title
        $errs = CustomDialplanService::validateData([
            'title' => '',
            'context_name' => 'valid-context',
            'dialplan_content' => 'exten => s,1,Hangup()',
        ]);
        $this->assertNotEmpty($errs);

        // Invalid context name characters
        $errsInvalid = CustomDialplanService::validateData([
            'title' => 'Title',
            'context_name' => 'invalid context name with spaces!',
            'dialplan_content' => 'exten => s,1,Hangup()',
        ]);
        $this->assertNotEmpty($errsInvalid);

        // Reserved context name
        $errsReserved = CustomDialplanService::validateData([
            'title' => 'Title',
            'context_name' => 'from-internal-pbx',
            'dialplan_content' => 'exten => s,1,Hangup()',
        ]);
        $this->assertNotEmpty($errsReserved);
        $this->assertStringContainsString('reserved', $errsReserved[0]);

        // Duplicate context name
        $uniqueCtx = 'ctx-dup-' . bin2hex(random_bytes(4));
        $firstId = CustomDialplanRepository::create([
            'title' => 'First Context',
            'context_name' => $uniqueCtx,
            'dialplan_content' => 'exten => s,1,Hangup()',
            'include_in_internal' => 1,
            'is_active' => 1,
        ]);
        $this->createdIds[] = $firstId;

        // Try creating another with same context name
        $errsDup = CustomDialplanService::validateData([
            'title' => 'Second Context',
            'context_name' => $uniqueCtx,
            'dialplan_content' => 'exten => s,1,Hangup()',
        ]);
        $this->assertNotEmpty($errsDup);
        $this->assertStringContainsString('already exists', $errsDup[0]);

        // Validating the same ID should be allowed (updating self)
        $errsSelf = CustomDialplanService::validateData([
            'title' => 'First Context Updated',
            'context_name' => $uniqueCtx,
            'dialplan_content' => 'exten => s,1,Hangup()',
        ], $firstId);
        $this->assertEmpty($errsSelf);
    }

    public function testTemplatesLibrary(): void
    {
        $templates = CustomDialplanService::getTemplates();
        $this->assertArrayHasKey('intercom', $templates);
        $this->assertArrayHasKey('spy_directed', $templates);
        $this->assertArrayHasKey('paging_group', $templates);
        $this->assertArrayHasKey('crm_webhook', $templates);
        $this->assertArrayHasKey('vip_inbound', $templates);
        $this->assertArrayHasKey('disa_secure', $templates);

        foreach ($templates as $key => $tpl) {
            $this->assertNotEmpty($tpl['title']);
            $this->assertNotEmpty($tpl['context_name']);
            $this->assertNotEmpty($tpl['content']);
            $syntax = CustomDialplanService::validateSyntax($tpl['content']);
            $this->assertTrue($syntax['valid'], "Template {$key} syntax is invalid: " . implode(', ', $syntax['errors']));
        }
    }

    public function testConfGenerator(): void
    {
        $entries = [
            [
                'id' => 1,
                'title' => 'Intercom Page',
                'context_name' => 'custom-intercom',
                'description' => 'Two-way intercom',
                'include_in_internal' => 1,
                'dialplan_content' => "exten => _*80.,1,NoOp(Intercom)\n same => n,Hangup()\n",
                'is_active' => 1,
            ],
            [
                'id' => 2,
                'title' => 'CRM Hook',
                'context_name' => 'custom-crm',
                'description' => 'External webhook',
                'include_in_internal' => 0,
                'dialplan_content' => "exten => s,1,NoOp(CRM)\n same => n,Return()\n",
                'is_active' => 1,
            ],
        ];

        $conf = buildCustomDialplanConf($entries);

        $this->assertStringContainsString('[custom-dialplan-pbx]', $conf);
        $this->assertStringContainsString('include => custom-intercom', $conf);
        // custom-crm has include_in_internal = 0, so it should NOT be in [custom-dialplan-pbx]
        $this->assertStringNotContainsString('include => custom-crm', $conf);

        // Both context headers must be rendered
        $this->assertStringContainsString('[custom-intercom]', $conf);
        $this->assertStringContainsString('[custom-crm]', $conf);
        $this->assertStringContainsString('exten => _*80.,1,NoOp(Intercom)', $conf);
        $this->assertStringContainsString('exten => s,1,NoOp(CRM)', $conf);
    }

    public function testServiceSaveAndPendingSync(): void
    {
        require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

        $ctxName = 'test-sync-' . bin2hex(random_bytes(4));
        $res = CustomDialplanService::save([
            'title' => 'Pending Sync Test',
            'context_name' => $ctxName,
            'dialplan_content' => "exten => *99,1,NoOp(Pending Sync)\n same => n,Hangup()\n",
            'include_in_internal' => 1,
            'sort_order' => 20,
            'is_active' => 1,
        ], 1);

        $this->assertTrue($res['success']);
        $id = $res['id'];
        $this->createdIds[] = $id;

        // Pending sync must have a row in 'custom_dialplan' domain
        $pending = getPendingSyncList();
        $this->assertArrayHasKey('custom_dialplan', $pending);

        // Apply pending sync
        $results = applyPendingSync(1);
        $this->assertArrayHasKey('custom_dialplan', $results);
        $this->assertTrue($results['custom_dialplan']['success']);

        // Check generated file in test conf dir
        $confPath = ASTERISK_PBX_DIR . '/extensions_custom.conf';
        $this->assertFileExists($confPath);
        $content = file_get_contents($confPath);
        $this->assertStringContainsString($ctxName, $content);
        $this->assertStringContainsString('exten => *99,1,NoOp(Pending Sync)', $content);
    }

    public function testServiceToggleAndDeletePendingSync(): void
    {
        require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

        $ctxName = 'test-toggle-' . bin2hex(random_bytes(4));
        $res = CustomDialplanService::save([
            'title' => 'Toggle Sync Test',
            'context_name' => $ctxName,
            'dialplan_content' => "exten => *88,1,NoOp(Toggle)\n same => n,Hangup()\n",
            'include_in_internal' => 1,
            'is_active' => 1,
        ], 1);

        $this->assertTrue($res['success']);
        $id = $res['id'];
        $this->createdIds[] = $id;

        // Apply initial sync
        applyPendingSync(1);
        $this->assertEmpty(getPendingSyncList());

        // Toggle active -> should mark pending sync
        $toggled = CustomDialplanService::toggleActive($id, 1);
        $this->assertTrue($toggled);
        $pendingAfterToggle = getPendingSyncList();
        $this->assertArrayHasKey('custom_dialplan', $pendingAfterToggle);

        applyPendingSync(1);
        $this->assertEmpty(getPendingSyncList());

        // Delete -> should mark pending sync
        $deleted = CustomDialplanService::delete($id, 1);
        $this->assertTrue($deleted);
        $pendingAfterDelete = getPendingSyncList();
        $this->assertArrayHasKey('custom_dialplan', $pendingAfterDelete);
    }

    public function testRejectsInlineReservedContextInBodyAndSanitizesCrmTemplate(): void
    {
        $res = CustomDialplanService::validateSyntax("exten => *90,1,NoOp(Ok)\n[from-internal-pbx]\nexten => *91,1,Hangup()\n");
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty($res['errors']);

        $templates = CustomDialplanService::getTemplates();
        $this->assertArrayHasKey('crm_webhook', $templates);
        $this->assertStringContainsString('FILTER(0123456789+', $templates['crm_webhook']['content']);
    }

    public function testValidatorRefusesFileDirectivesAndExtendingReservedContexts(): void
    {
        foreach (['#include /etc/passwd', '#exec /bin/id', '#tryinclude x.conf', ' # tryexec /bin/id'] as $line) {
            $this->assertFalse(CustomDialplanService::validateSyntax($line)['valid'], $line);
        }
        foreach (['[from-internal-pbx-ortak](+)', '[from-internal-pbx-ortak](!)', '[from-internal-pbx-ortak] ; comment'] as $line) {
            $this->assertFalse(CustomDialplanService::validateSyntax($line)['valid'], $line);
        }
        $this->assertTrue(CustomDialplanService::validateSyntax("[my-own](+)\nexten => 1,1,NoOp()")['valid']);
    }

    public function testRiskyTemplatesCarryARandomPin(): void
    {
        $t = CustomDialplanService::getTemplates();
        $this->assertStringNotContainsString('Authenticate(1234)', $t['disa_secure']['content']);
        $this->assertMatchesRegularExpression('/Authenticate\(\d{8}\)/', $t['disa_secure']['content']);
        $this->assertSame(2, preg_match_all('/Authenticate\(\d{6}\)/', $t['spy_directed']['content']));
    }
}
