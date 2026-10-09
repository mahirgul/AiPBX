<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/IvrCallFlowService.php';

/**
 * #16: the IVR call flow tree — keys, no input and invalid key branches,
 * menus / time conditions / announcements opened up in place, loops and
 * missing targets marked instead of recursing or failing.
 */
final class IvrCallFlowTest extends TestCase
{
    private function data(): array
    {
        return [
            'ivrs' => [
                '1' => ['id' => 1, 'title' => 'Main', 'prompt_file' => 'custom/welcome', 'timeout_seconds' => 7, 'allow_direct_dial' => 1,
                        'timeout_dest_type' => 'queue', 'timeout_dest_id' => 'q1', 'invalid_dest_type' => 'hangup', 'invalid_dest_id' => '', 'is_active' => 1],
                '2' => ['id' => 2, 'title' => 'Sales menu', 'prompt_file' => 'custom/sales', 'timeout_seconds' => 5, 'allow_direct_dial' => 0,
                        'timeout_dest_type' => 'ivr', 'timeout_dest_id' => '1', 'invalid_dest_type' => 'hangup', 'invalid_dest_id' => '', 'is_active' => 1],
            ],
            'entries' => [
                '1' => [
                    ['ivr_id' => 1, 'digit' => '1', 'dest_type' => 'ivr', 'dest_id' => '2'],
                    ['ivr_id' => 1, 'digit' => '2', 'dest_type' => 'time_condition', 'dest_id' => '5'],
                    ['ivr_id' => 1, 'digit' => '3', 'dest_type' => 'extension', 'dest_id' => '999'],
                ],
                '2' => [
                    ['ivr_id' => 2, 'digit' => '9', 'dest_type' => 'ivr', 'dest_id' => '2'],
                ],
            ],
            'time_conditions' => [
                '5' => ['id' => 5, 'title' => 'Office hours', 'match_dest_type' => 'extension', 'match_dest_id' => '1001',
                        'nomatch_dest_type' => 'announcement', 'nomatch_dest_id' => '8', 'is_active' => 1],
            ],
            'announcements' => [
                '8' => ['id' => 8, 'title' => 'Closed', 'audio_file' => 'custom/closed', 'post_dest_type' => 'hangup', 'post_dest_id' => ''],
            ],
        ];
    }

    private function labeler(): callable
    {
        return fn(string $type, string $id) => ['queue:q1' => 'Support', 'extension:1001' => '1001 - Ali'][$type . ':' . $id] ?? null;
    }

    private function child(array $node, string $edge): array
    {
        foreach ($node['children'] as $c) {
            if ($c['edge'] === $edge) {
                return $c['node'];
            }
        }
        $this->fail("No branch $edge");
    }

    public function testTreeFollowsMenusTimeConditionsAndAnnouncements(): void
    {
        $tree = IvrCallFlowService::build('ivr', '1', $this->data(), $this->labeler());

        $this->assertSame('ivr', $tree['kind']);
        $this->assertSame('Main', $tree['label']);
        $this->assertSame(['key:1', 'key:2', 'key:3', 'timeout:7', 'invalid'], array_column($tree['children'], 'edge'));

        $sales = $this->child($tree, 'key:1');
        $this->assertSame('ivr', $sales['kind']);
        // 9 = repeat the same menu, and no input goes back to the main menu: loops, not recursion.
        $this->assertSame('loop', $this->child($sales, 'key:9')['kind']);
        $this->assertSame('loop', $this->child($sales, 'timeout:5')['kind']);

        $tc = $this->child($tree, 'key:2');
        $this->assertSame('time_condition', $tc['kind']);
        $this->assertSame('1001 - Ali', $this->child($tc, 'match')['label']);
        $closed = $this->child($tc, 'nomatch');
        $this->assertSame('announcement', $closed['kind']);
        $this->assertSame('hangup', $this->child($closed, 'after')['type']);

        $this->assertSame('missing', $this->child($tree, 'key:3')['kind']);
        $this->assertSame('Support', $this->child($tree, 'timeout:7')['label']);
    }

    public function testMissingIvrAndDepthLimit(): void
    {
        $this->assertSame('missing', IvrCallFlowService::build('ivr', '404', $this->data(), $this->labeler())['kind']);

        // A long chain of distinct menus is cut at MAX_DEPTH.
        $data = ['ivrs' => [], 'entries' => [], 'time_conditions' => [], 'announcements' => []];
        for ($i = 1; $i <= 10; $i++) {
            $data['ivrs'][(string) $i] = ['id' => $i, 'title' => "M$i", 'prompt_file' => 'p', 'timeout_seconds' => 3,
                'timeout_dest_type' => 'ivr', 'timeout_dest_id' => (string) ($i + 1), 'invalid_dest_type' => 'hangup', 'invalid_dest_id' => ''];
        }
        $node = IvrCallFlowService::build('ivr', '1', $data, $this->labeler());
        for ($d = 0; $d < IvrCallFlowService::MAX_DEPTH; $d++) {
            $this->assertSame('ivr', $node['kind']);
            $node = $node['children'][0]['node'];
        }
        $this->assertSame('more', $node['kind']);
    }

    public function testRenderEscapesNames(): void
    {
        $data = $this->data();
        $data['ivrs']['1']['title'] = '<b>Main</b>';
        $html = IvrCallFlowService::render(IvrCallFlowService::build('ivr', '1', $data, $this->labeler()));
        $this->assertStringContainsString('&lt;b&gt;Main&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Main</b>', $html);
        $this->assertStringContainsString('class="ivr-flow"', $html);
    }
}
