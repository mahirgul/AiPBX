<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/AiAppService.php';
require_once __DIR__ . '/../../src/services/ai/AgentInstructions.php';

final class AgentInstructionsTest extends TestCase
{
    private const TARGETS = ['extension' => ['1000' => 'Operator', '200' => 'Ali'], 'queue' => ['support' => 'Support', '200' => 'Q200'],
        'ring_group' => ['2' => 'Sales']];
    private const QUERIES = ['order_status' => 'Status of an order'];
    private const BASES = ['Price list' => ['id' => 7, 'description' => 'Products and prices'], 'Empty' => ['id' => 8, 'description' => '']];
    private const PBX = ['find_person', 'send_dtmf'];
    private const LANGS = ['tr', 'en', 'de'];

    private static function parse(string $text): array
    {
        return AgentInstructions::parse($text, self::TARGETS, self::QUERIES, self::BASES, self::PBX, self::LANGS);
    }

    private function refused(string $text, string $reason): void
    {
        try {
            self::parse($text);
            $this->fail('accepted: ' . $text);
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(t('builtin_ai_agent.why_' . $reason), $e->getMessage(), $text);
        }
    }

    public function testEveryToolIsReadFromTheInstructions(): void
    {
        $r = self::parse("Be brief.\n{greeting, 2, tr, İkinci, selam}\n{greeting, 1, tr, Merhaba {caller_name}, hoş geldiniz.}\n"
            . "{greeting, 1, en, Hello {caller_name}.}\n"
            . "Callers who want to order go to {transfer, sales, group:2}. Ask for a person: {transfer, operator, 1000}.\n"
            . "Check orders with {db, order_status} and prices in {knowledge, price list}.\n"
            . "Delivery dates: {http, delivery, https://crm.example.com/d?no={order_no}}. Use {pbx, find_person}.\n"
            . "{waiting, One moment, please.}");
        $this->assertSame(['tr' => [['order' => 1, 'text' => 'Merhaba {caller_name}, hoş geldiniz.'], ['order' => 2, 'text' => 'İkinci, selam']],
            'en' => [['order' => 1, 'text' => 'Hello {caller_name}.']]], $r['greetings_by_lang'], 'ordered by the number; commas belong to the text');
        $this->assertSame(['sales', 'ring_group', '2'], [$r['transfers'][0]['id'], $r['transfers'][0]['type'], $r['transfers'][0]['target']]);
        $this->assertSame('Callers who want to order go to sales.', $r['transfers'][0]['usage'], 'the sentence says when');
        $this->assertSame(['extension', '1000'], [$r['transfers'][1]['type'], $r['transfers'][1]['target']]);
        $this->assertSame(['order_status'], $r['db_queries']);
        $this->assertSame([7], $r['knowledge_collections'], 'a knowledge base by its name, any case');
        $this->assertSame(['find_person'], $r['pbx_tools']);
        $this->assertSame(['One moment, please.'], $r['waiting_phrases']);
        $this->assertStringContainsString('https://crm.example.com/d?no={order_no}', $r['http_tools'], 'a URL may hold variables');
        // what the model reads: names instead of brackets, no greeting or waiting lines, and the list of actions
        $this->assertStringContainsString('go to sales (Sales).', $r['text']);
        $this->assertStringNotContainsString('{greeting', $r['text']);
        $this->assertStringNotContainsString('{waiting', $r['text']);
        $this->assertStringContainsString("# Actions you can use\n- Transfer `sales` to Sales: Callers who want to order go to sales.", $r['text']);
        $this->assertStringContainsString('- Knowledge base `Price list`: Products and prices', $r['text']);
    }

    public function testVariablesPlainBracketsAndEscapesStayText(): void
    {
        $r = self::parse('Hello {caller_name}. JSON looks like \{"a": 1}. {note, this is not a tool}');
        $this->assertSame('Hello {caller_name}. JSON looks like {"a": 1}. {note, this is not a tool}', $r['text']);
        $this->assertSame([], $r['transfers']);
    }

    public function testWrongBracketsAreRefusedWithTheirReason(): void
    {
        $this->refused('{transfer, x, 9999}', 'target');
        $this->refused('{transfer, x, 200}', 'ambiguous');            // extension 200 and queue 200
        $this->refused('{transfer, end_call, 1000}', 'reserved');
        $this->refused('{transfer, Bad Name, 1000}', 'name');
        $this->refused('{transfer, a, 1000} {transfer, a, 1000}', 'duplicate');
        $this->refused('{transfer, a}', 'format');
        $this->refused('{greeting, one, tr, Merhaba}', 'format');
        $this->refused('{greeting, 1, fr, Bonjour}', 'language');
        $this->refused('{db, missing}', 'unknown');
        $this->refused('{knowledge, Nothing}', 'unknown');
        $this->refused('{knowledge, Empty}', 'description');
        $this->refused('{pbx, rm_rf}', 'unknown');
        $this->refused('{http, local, http://127.0.0.1/x}', 'url');
        $this->refused(str_repeat("{waiting, wait}\n", 6), 'limit');
        $this->assertSame(['support'], array_column(self::parse('{transfer, help, queue:support}')['transfers'], 'target'));
        $this->assertSame(['200'], array_column(self::parse('{transfer, ali, extension:200}')['transfers'], 'target'));
    }

    public function testEmptiedHeadingsAndListMarksDoNotReachTheModel(): void
    {
        $r = self::parse("# Role\nBe kind.\n\n# Greetings\n{greeting, 1, en, Hi}\n\n# Tools\n- Ask for a person: {transfer, op, 1000}.\n\n# Waiting\n{waiting, Wait}\n");
        $this->assertStringNotContainsString('# Greetings', $r['text']);
        $this->assertStringNotContainsString('# Waiting', $r['text']);
        $this->assertStringContainsString("# Tools\n- Ask for a person: op (Operator).", $r['text']);
        $this->assertSame('Ask for a person: op.', $r['transfers'][0]['usage']);
    }

    public function testOptionsAreWrittenInTheInstructionsToo(): void
    {
        $r = self::parse("{language, de}\n{barge_in, off}\n{announcements, on}\n{email, calls@example.com}\nBe kind.");
        $this->assertSame(['barge_in' => false, 'announcements' => true, 'email' => 'calls@example.com', 'language' => 'de'], $r['options']);
        $this->assertSame('Be kind.', $r['text'], 'options are not read by the model (and the e-mail address stays out of it)');
        $none = self::parse('Only text.')['options'];
        $this->assertSame([null, null, '', ''], [$none['barge_in'], $none['announcements'], $none['email'], $none['language']], 'absent = the default');
        $this->refused('{barge_in, maybe}', 'format');
        $this->refused('{email, not-an-address}', 'email');
        $this->refused('{language, fr}', 'language');
        $this->refused("{barge_in, on}\n{barge_in, off}", 'limit');
    }

    public function testTheErrorNamesTheLine(): void
    {
        try {
            self::parse("one\ntwo\n{transfer, x, 9999}");
            $this->fail('accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(sprintf(t('builtin_ai_agent.err_instruction_tool'), 3, '{transfer, x, 9999}', ''), $e->getMessage() . ' ');
        }
    }
}
