<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/services/FaxCoverService.php';

final class FaxCoverTest extends TestCase
{
    public function testTheCoverFollowsTheMode(): void
    {
        $this->assertFalse(FaxCoverService::wanted(['cover_enabled' => '1'], ['mode' => 'off']));
        $this->assertTrue(FaxCoverService::wanted(['cover_enabled' => '1'], ['mode' => 'optional']));
        $this->assertFalse(FaxCoverService::wanted([], ['mode' => 'optional']));
        $this->assertTrue(FaxCoverService::wanted([], ['mode' => 'required']), 'the sender cannot turn off a required cover');
    }

    public function testEverythingPeopleTypeIsEscaped(): void
    {
        $evil = '<img src="http://attacker.example/x.png"><script>x</script>';
        $html = FaxCoverService::html(
            ['to' => $evil, 'subject' => $evil, 'message' => $evil],
            ['company' => $evil, 'notice' => $evil],
            ['name' => $evil, 'number' => '1000', 'to_number' => '2000', 'date' => '2026-11-02 10:00', 'pages' => 3]
        );
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function testTheFactsAreOnThePage(): void
    {
        $html = FaxCoverService::html(
            ['to' => 'Acme', 'subject' => 'Invoice', 'message' => "Line one\nLine two"],
            ['company' => 'Our Company', 'notice' => 'Confidential'],
            ['name' => 'Sender', 'number' => '902125550000', 'to_number' => '02129990001', 'date' => '2026-11-02 10:00', 'pages' => 4]
        );
        foreach (['Acme', 'Invoice', 'Our Company', 'Confidential', 'Sender', '902125550000', '02129990001', '2026-11-02 10:00', '>4<', 'Line one<br'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
        $this->assertStringNotContainsString('<img', $html, 'no logo unless asked');
        $this->assertStringContainsString('<img src="/x/logo.png"', FaxCoverService::html(
            ['to' => '', 'subject' => '', 'message' => ''],
            ['company' => '', 'notice' => ''],
            ['name' => '', 'number' => '', 'to_number' => '', 'date' => '', 'pages' => 1],
            '/x/logo.png'
        ));
    }

    public function testTypedFieldsAreLimited(): void
    {
        $fields = FaxCoverService::fieldsFromPost(['cover_to' => str_repeat('a', 500), 'cover_subject' => ' b ', 'cover_message' => str_repeat('c', 5000)]);
        $this->assertSame(120, mb_strlen($fields['to']));
        $this->assertSame('b', $fields['subject']);
        $this->assertSame(2000, mb_strlen($fields['message']));
    }
}
