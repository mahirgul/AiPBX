<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/MailTemplateService.php';
require_once __DIR__ . '/../../src/services/VoicemailMailParser.php';
require_once __DIR__ . '/../../src/services/UserInvitationService.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';
require_once __DIR__ . '/../../src/sync/SyncVoicemail.php';

/** E-mail templates: sanitising, rendering, per-language overrides, MIME, voicemail mailcmd. */
final class MailTemplateTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->exec("DELETE FROM mail_templates");
        $this->db->exec("DELETE FROM sys_settings WHERE setting_key = 'mail_default_language'");
        $this->db->exec("DELETE FROM sys_users WHERE username IN ('mailtpl_de', 'mailtpl_vm')");
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    public function testSanitizeKeepsAllowedMarkupOnly(): void
    {
        $out = MailTemplateService::sanitize(
            '<p onclick="x()">Hi <strong>{name}</strong><script>alert(1)</script></p>'
            . '<a href="javascript:alert(1)">bad</a> <a href="{reset_link}" style="color:red">ok</a> <a href="https://x.test/a">web</a>'
            . '<div><img src="x" onerror="y">text</div><!-- c -->'
        );
        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringNotContainsString('<div', $out);
        $this->assertStringContainsString('<strong>{name}</strong>', $out);
        $this->assertStringContainsString('<a href="{reset_link}">ok</a>', $out);
        $this->assertStringContainsString('<a href="https://x.test/a">web</a>', $out);
        $this->assertStringContainsString('text', $out);
    }

    public function testRenderEscapesValuesAndInsertsBlocks(): void
    {
        $mail = MailTemplateService::render('test', 'en', ['date' => '1.1.2026', 'from_name' => '<b>x</b>', 'from_address' => 'a@b.c', 'to' => 't@e.st', 'host' => 'h'], [], [
            'subject' => 'Hello {to} {unknown}',
            'body' => '<p>{from_name} {cta}</p>',
        ]);
        $this->assertSame('Hello t@e.st {unknown}', $mail['subject']);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $mail['html']);
        $this->assertStringContainsString('<x>', MailTemplateService::render('test', 'en', ['from_name' => '<x>'], [], ['subject' => 's', 'body' => '<p>{from_name}</p>'])['text']);

        $btn = MailTemplateService::buttonBlock('https://pbx.test/r?t=1', 'Go');
        $m = MailTemplateService::render('password_reset', 'en', ['name' => 'N', 'username' => 'u', 'reset_link' => 'https://pbx.test/r?t=1'], ['reset_button' => $btn]);
        $this->assertStringContainsString('href="https://pbx.test/r?t=1"', $m['html']);
        $this->assertStringContainsString('Go: https://pbx.test/r?t=1', $m['text']);
        $this->assertMatchesRegularExpression('/\nGo: https:\/\/pbx\.test\/r\?t=1\n\n/', $m['text'], 'the button line stands alone');
        $this->assertStringNotContainsString('{reset_button}', $m['html']);
    }

    public function testEveryTemplateHasBuiltInTextInEnglishAndTurkish(): void
    {
        foreach (array_keys(MailTemplateService::TEMPLATES) as $key) {
            foreach (['en', 'tr'] as $lang) {
                $t = MailTemplateService::builtIn($key, $lang);
                $this->assertNotSame("mailtpl.{$key}.subject", $t['subject'], "$key/$lang subject");
                $this->assertNotSame("mailtpl.{$key}.body", $t['body'], "$key/$lang body");
                // the built-in text only uses the template's own variables
                preg_match_all('/\{([a-z_]+)\}/', $t['subject'] . $t['body'], $m);
                $this->assertSame([], array_diff($m[1], MailTemplateService::variables($key)), "$key/$lang variables");
            }
        }
    }

    public function testOverrideIsPerLanguageAndResettable(): void
    {
        $res = MailTemplateService::save('fax_sent', 'de', 'Fax an {destination}', '<p>Fax <em>ok</em><script>x</script></p>');
        $this->assertTrue($res['success']);
        $this->assertSame('<p>Fax <em>ok</em></p>', $res['body']);
        $this->assertTrue(MailTemplateService::get('fax_sent', 'de')['custom']);
        $this->assertFalse(MailTemplateService::get('fax_sent', 'en')['custom']);
        $this->assertSame('Fax an +1', MailTemplateService::render('fax_sent', 'de', ['destination' => '+1'])['subject']);

        $this->assertFalse(MailTemplateService::save('fax_sent', 'de', '', '<p>x</p>')['success']);
        $this->assertFalse(MailTemplateService::save('nope', 'de', 's', '<p>x</p>')['success']);

        MailTemplateService::reset('fax_sent', 'de');
        $this->assertFalse(MailTemplateService::get('fax_sent', 'de')['custom']);
        $this->assertArrayNotHasKey('fax_sent', MailTemplateService::customizedLanguages());
    }

    public function testRecipientLanguage(): void
    {
        $this->db->exec("INSERT INTO sys_users (username, password_hash, full_name, email, role, language_preference, is_active) VALUES ('mailtpl_de', '', 'D', 'de-user@example.com', 'user', 'de', 1)");
        $id = (int) $this->db->query("SELECT id FROM sys_users WHERE username = 'mailtpl_de'")->fetchColumn();
        $this->assertSame('de', MailTemplateService::languageFor('de-user@example.com'));
        $this->assertSame('de', MailTemplateService::languageFor(null, $id));

        $this->db->exec("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('mail_default_language', 'fr')");
        $this->assertSame('fr', MailTemplateService::languageFor('nobody@example.com'));
    }

    public function testMimeHasAlternativePartsAndAttachment(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($file, '%PDF-1.4 test');
        [$headers, $body] = MailTemplateService::buildMime(
            ['subject' => 'S', 'html' => '<p>H</p>', 'text' => 'T'],
            'from@example.com', 'Ünïcode Name', [['path' => $file, 'name' => 'fax.pdf', 'type' => 'application/pdf']]
        );
        unlink($file);
        $this->assertStringContainsString('From: =?UTF-8?B?', $headers);
        $this->assertStringContainsString('Content-Type: multipart/mixed', $headers);
        $this->assertStringContainsString('multipart/alternative', $body);
        $this->assertStringContainsString('text/plain; charset=UTF-8', $body);
        $this->assertStringContainsString('text/html; charset=UTF-8', $body);
        $this->assertStringContainsString('filename="fax.pdf"', $body);
        $this->assertStringContainsString(base64_encode('%PDF-1.4 test'), $body);
    }

    public function testVoicemailMessageFromAsteriskIsParsed(): void
    {
        $wav = random_bytes(64);
        $raw = "Date: Thu, 8 Oct 2026 10:00:00 +0200\nFrom: \"Asterisk PBX\" <asterisk>\nTo: \"Alex\" <alex@example.com>\n"
            . "Subject: Voicemail 1001\nMIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=\"----voicemail_1234\"\n\n"
            . "This is a multi-part message in MIME format.\n\n------voicemail_1234\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n\n"
            . "AIPBX-VM\nmailbox=1001\nname=Alex\nmsgnum=1\nduration=0:42\ncidnum=+431234\ncidname=John Smith\ncallerid=\"John Smith\" <+431234>\ndate=08.10.2026 10:00\n\n"
            . "------voicemail_1234\nContent-Type: audio/x-wav; name=\"msg0000.wav\"\nContent-Transfer-Encoding: base64\nContent-Disposition: attachment; filename=\"msg0000.wav\"\n\n"
            . chunk_split(base64_encode($wav), 72, "\n") . "\n------voicemail_1234--\n";
        $msg = VoicemailMailParser::parse($raw);
        $this->assertNotNull($msg);
        $this->assertSame('alex@example.com', $msg['to']);
        $this->assertSame('1001', $msg['info']['mailbox']);
        $this->assertSame('msg0000.wav', $msg['attachment']['name']);
        $this->assertSame($wav, $msg['attachment']['data']);

        $vars = VoicemailMailParser::templateVars($msg['info'], ['full_name' => 'Alex Morgan']);
        $this->assertSame('John Smith <+431234>', $vars['caller']);
        $this->assertSame('Alex Morgan', $vars['name']);
        $this->assertSame('0:42', $vars['duration']);

        $this->assertNull(VoicemailMailParser::parse("To: a@b.c\nSubject: x\n\nplain text"));
    }

    public function testVoicemailConfigPointsAsteriskAtTheScript(): void
    {
        $conf = voicemailGeneralConf();
        $this->assertMatchesRegularExpression('#^mailcmd=/usr/bin/php .*/bin/voicemail_mail\.php$#m', $conf);
        $this->assertStringContainsString('emailbody=AIPBX-VM\\nmailbox=${VM_MAILBOX}', $conf);
        $this->assertFileExists(dirname(__DIR__, 2) . '/bin/voicemail_mail.php');
    }

    public function testSamplePreviewRendersEveryTemplate(): void
    {
        foreach (array_keys(MailTemplateService::TEMPLATES) as $key) {
            $s = MailTemplateService::sampleData($key, 'en');
            $m = MailTemplateService::render($key, 'en', $s['vars'], $s['blocks']);
            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $m['subject'] . $m['html'], $key);
        }
    }
}
