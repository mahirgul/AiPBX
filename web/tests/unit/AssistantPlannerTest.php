<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/ai/AssistantPlanner.php';

/**
 * AI -> Assistant: a request becomes a PBX change only if it survives this.
 *
 * The planner is pure, so these tests need no database and no model: the
 * "model" is a callable, which is exactly what makes an untrusted reply
 * testable.
 */
final class AssistantPlannerTest extends TestCase
{
    /** A small but realistic server: two extensions, one ring group, one queue. */
    private function context(): array
    {
        return AssistantPlanner::context(
            [
                ['extension' => '101', 'name' => 'Front desk'],
                ['extension' => '102', 'name' => 'Warehouse'],
                ['extension' => '203', 'name' => 'Support'],
            ],
            [['id' => 4, 'title' => 'Sales']],
            [['id' => 7, 'title' => 'Support queue']]
        );
    }

    /** A model that answers with whatever the test hands it, and counts calls. */
    private function model(?string $reply, ?int &$calls = null): callable
    {
        return function (string $prompt) use ($reply, &$calls) {
            $calls = (int) $calls + 1;
            return $reply;
        };
    }

    public function testEnglishPhrasingIsUnderstoodByTheRules(): void
    {
        $plan = AssistantPlanner::plan('Forward 101 to 203', $this->context());
        $this->assertTrue($plan['ok']);
        $this->assertSame('extension.forward', $plan['action']);
        $this->assertSame(['extension' => '101', 'target' => '203', 'enabled' => true],
            $plan['arguments']);
        $this->assertSame('rules', $plan['source']);
    }

    public function testTurkishPhrasingIsUnderstoodByTheRules(): void
    {
        $plan = AssistantPlanner::plan('101 numaralı dahiliyi 203 e yönlendir', $this->context());
        $this->assertTrue($plan['ok']);
        $this->assertSame('extension.forward', $plan['action']);
        $this->assertSame('101', $plan['arguments']['extension']);
        $this->assertSame('203', $plan['arguments']['target']);
    }

    public function testForwardingToAnOutsideNumberIsAllowed(): void
    {
        $plan = AssistantPlanner::plan('Forward 101 to 15551234567', $this->context());
        $this->assertTrue($plan['ok']);
        $this->assertSame('15551234567', $plan['arguments']['target']);
    }

    public function testForwardingToAnExtensionThatDoesNotExistIsRefused(): void
    {
        $plan = AssistantPlanner::plan('Forward 101 to 999', $this->context());
        // 999 looks like a number, not an extension of this server, so it is
        // treated as an outside number — and the outside number is kept.
        $this->assertTrue($plan['ok']);
        $this->assertSame('999', $plan['arguments']['target']);

        // But the extension being changed must exist.
        $refused = AssistantPlanner::plan('Forward 999 to 101', $this->context());
        $this->assertFalse($refused['ok']);
    }

    public function testVoicemailOffAndOnInBothLanguages(): void
    {
        $off = AssistantPlanner::plan('Turn off voicemail for 102', $this->context());
        $this->assertTrue($off['ok']);
        $this->assertSame('extension.voicemail', $off['action']);
        $this->assertFalse($off['arguments']['enabled']);

        $on = AssistantPlanner::plan('102 telesekreter aç', $this->context());
        $this->assertTrue($on['ok']);
        $this->assertTrue($on['arguments']['enabled']);
    }

    public function testRingGroupMembersAndTimeout(): void
    {
        $members = AssistantPlanner::plan('Add 101 and 102 to the Sales ring group', $this->context());
        $this->assertTrue($members['ok']);
        $this->assertSame('ringgroup.members', $members['action']);
        $this->assertSame(4, $members['arguments']['group']);
        $this->assertSame(['101', '102'], $members['arguments']['extensions']);

        $timeout = AssistantPlanner::plan('Sales ring group timeout 20 seconds', $this->context());
        $this->assertTrue($timeout['ok']);
        $this->assertSame('ringgroup.timeout', $timeout['action']);
        $this->assertSame(20, $timeout['arguments']['seconds']);
    }

    public function testAGreetingIsTakenFromTheQuotedText(): void
    {
        $plan = AssistantPlanner::plan(
            'Set the greeting for 101 to "Thank you for calling, we open at nine."',
            $this->context()
        );
        $this->assertTrue($plan['ok']);
        $this->assertSame('voicemail.greeting', $plan['action']);
        $this->assertSame('Thank you for calling, we open at nine.', $plan['arguments']['text']);
    }

    public function testAnUnrecognisedRequestAsksTheModelInsteadOfGuessing(): void
    {
        $calls = 0;
        $reply = json_encode(['action' => 'queue.timeout',
                              'arguments' => ['queue' => 7, 'seconds' => 45]]);
        $plan = AssistantPlanner::plan('the support people should ring for longer',
            $this->context(), $this->model($reply, $calls));

        $this->assertSame(1, $calls, 'the model should have been asked once');
        $this->assertTrue($plan['ok']);
        $this->assertSame('queue.timeout', $plan['action']);
        $this->assertSame(45, $plan['arguments']['seconds']);
        $this->assertSame('model', $plan['source']);
    }

    public function testTheModelIsNotAskedWhenTheRulesAlreadyUnderstand(): void
    {
        $calls = 0;
        $plan = AssistantPlanner::plan('Forward 101 to 203', $this->context(),
            $this->model('{"action":"none"}', $calls));
        $this->assertTrue($plan['ok']);
        $this->assertSame(0, $calls, 'a clear request must not cost a model call');
    }

    public function testNoModelConfiguredStillAnswersTheEverydayRequests(): void
    {
        $plan = AssistantPlanner::plan('Forward 101 to 203', $this->context(), null);
        $this->assertTrue($plan['ok']);

        $unclear = AssistantPlanner::plan('make the phones nicer please', $this->context(), null);
        $this->assertFalse($unclear['ok']);
        $this->assertSame('ai_assistant.err_understood', $unclear['message_key']);
    }

    public function testAModelThatInventsAnActionIsRefused(): void
    {
        $reply = '{"action":"trunk.delete_all","arguments":{}}';
        $plan = AssistantPlanner::plan('delete everything', $this->context(),
            $this->model($reply));
        $this->assertFalse($plan['ok']);
    }

    public function testAModelThatAddsAFieldIsRefused(): void
    {
        $reply = '{"action":"extension.rename","arguments":{"extension":"101","name":"Desk","admin":true}}';
        $plan = AssistantPlanner::plan('rename 101', $this->context(), $this->model($reply));
        $this->assertFalse($plan['ok'], 'a field outside the catalog must not pass');
    }

    public function testAModelValueOutOfRangeIsRefused(): void
    {
        $reply = '{"action":"queue.timeout","arguments":{"queue":7,"seconds":100000}}';
        $plan = AssistantPlanner::plan('make the queue ring forever', $this->context(),
            $this->model($reply));
        $this->assertFalse($plan['ok']);
    }

    public function testProseInsteadOfJsonIsRefused(): void
    {
        $plan = AssistantPlanner::plan('do something', $this->context(),
            $this->model('Sure! I have forwarded extension 101 to 203 for you.'));
        $this->assertFalse($plan['ok']);
    }

    public function testAFencedJsonReplyIsAccepted(): void
    {
        $reply = "```json\n{\"action\":\"extension.voicemail\",\"arguments\":{\"extension\":\"101\",\"enabled\":false}}\n```";
        $plan = AssistantPlanner::plan('stop taking messages on 101', $this->context(),
            $this->model($reply));
        $this->assertTrue($plan['ok']);
        $this->assertFalse($plan['arguments']['enabled']);
    }

    public function testTheModelAnsweringNoneIsARefusalNotACrash(): void
    {
        $plan = AssistantPlanner::plan('what is the weather', $this->context(),
            $this->model('{"action":"none"}'));
        $this->assertFalse($plan['ok']);
        $this->assertSame('ai_assistant.err_understood', $plan['message_key']);
    }

    public function testAnEmptyModelReplyOrNoModelIsAlsoARefusal(): void
    {
        foreach (['', '   ', null] as $reply) {
            $plan = AssistantPlanner::plan('something vague', $this->context(),
                $this->model($reply));
            $this->assertFalse($plan['ok']);
        }
    }

    public function testAGreetingWithMarkupIsRefused(): void
    {
        $plan = AssistantPlanner::build('voicemail.greeting',
            ['extension' => '101', 'text' => '<script>alert(1)</script>'], $this->context(), 'model');
        $this->assertFalse($plan['ok']);
    }

    public function testAnOverlongGreetingIsRefused(): void
    {
        $plan = AssistantPlanner::build('voicemail.greeting',
            ['extension' => '101', 'text' => str_repeat('a', AssistantPlanner::MAX_GREETING + 1)],
            $this->context(), 'model');
        $this->assertFalse($plan['ok']);
    }

    public function testAMissingRequiredFieldIsRefused(): void
    {
        $plan = AssistantPlanner::build('ringgroup.timeout',
            ['group' => 4], $this->context(), 'model');
        $this->assertFalse($plan['ok']);
        $this->assertSame('ai_assistant.err_missing', $plan['message_key']);
    }

    public function testAnUnknownActionBuiltDirectlyIsRefused(): void
    {
        $plan = AssistantPlanner::build('asterisk.restart', [], $this->context(), 'model');
        $this->assertFalse($plan['ok']);
        $this->assertSame('ai_assistant.err_unknown_action', $plan['message_key']);
    }

    public function testAClockAndWeekdayPlanIsValidated(): void
    {
        $ok = AssistantPlanner::build('timecondition.create', [
            'title' => 'Office hours', 'open' => '08:30', 'close' => '17:00',
            'days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        ], $this->context(), 'model');
        $this->assertTrue($ok['ok']);
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri'], $ok['arguments']['days']);

        $bad = AssistantPlanner::build('timecondition.create', [
            'title' => 'Office hours', 'open' => '25:00', 'close' => '17:00',
            'days' => ['mon'],
        ], $this->context(), 'model');
        $this->assertFalse($bad['ok'], '25:00 is not a time');

        $badDay = AssistantPlanner::build('timecondition.create', [
            'title' => 'Office hours', 'open' => '08:00', 'close' => '17:00',
            'days' => ['funday'],
        ], $this->context(), 'model');
        $this->assertFalse($badDay['ok']);
    }

    public function testThePlanCarriesTheModuleItWouldTouch(): void
    {
        $plan = AssistantPlanner::plan('Add 101 to the Sales ring group', $this->context());
        $this->assertSame('ring_groups', $plan['module']);
        $this->assertTrue(in_array($plan['module'],
            array_column(AssistantPlanner::ACTIONS, 'module'), true),
            'the module must be one this planner knows how to apply');
    }

    public function testEveryActionInTheCatalogCanBeBuiltFromValidArguments(): void
    {
        // A guard against adding an action whose fields no validator accepts.
        $arguments = [
            'extension' => '101', 'target' => '203', 'enabled' => true,
            'name' => 'Front desk', 'group' => 4, 'queue' => 7,
            'extensions' => ['101'], 'seconds' => 15, 'title' => 'Hours',
            'open' => '09:00', 'close' => '17:00', 'days' => ['mon'],
            'text' => 'Hello and welcome.',
        ];
        foreach (AssistantPlanner::ACTIONS as $action => $spec) {
            $subset = [];
            foreach (array_keys($spec['fields']) as $field) {
                $this->assertArrayHasKey($field, $arguments, "$action wants $field");
                $subset[$field] = $arguments[$field];
            }
            $plan = AssistantPlanner::build($action, $subset, $this->context(), 'rules');
            $this->assertTrue($plan['ok'], "$action with valid arguments was refused");
            $this->assertSame($action, $plan['action']);
        }
    }

    public function testThePromptOffersOnlyTheCatalogAndNamesTheKnownObjects(): void
    {
        $prompt = AssistantPlanner::prompt('forward 101 to 203', $this->context());
        foreach (array_keys(AssistantPlanner::ACTIONS) as $action) {
            $this->assertStringContainsString($action, $prompt);
        }
        $this->assertStringContainsString('101', $prompt);
        $this->assertStringContainsString('Sales', $prompt);
        // It is told plainly that it cannot change anything itself.
        $this->assertStringContainsString('cannot change anything', $prompt);
        $this->assertStringNotContainsString('asterisk', mb_strtolower($prompt));
        $this->assertStringNotContainsString('sql', mb_strtolower($prompt));
    }

    public function testAnEmptyRequestIsRefusedWithoutTroublingTheModel(): void
    {
        $calls = 0;
        $plan = AssistantPlanner::plan('   ', $this->context(), $this->model('{}', $calls));
        $this->assertFalse($plan['ok']);
        $this->assertSame(0, $calls);
    }
}
