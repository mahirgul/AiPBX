<?php
/**
 * IVR call flow (#16): what happens to a caller who enters an IVR, drawn as a
 * tree — the greeting, every key, "no input" and "invalid key", and where each
 * one goes. IVRs, time conditions and announcements that the flow passes
 * through are opened up in place, so a menu → sub-menu → queue chain reads in
 * one picture. Editing stays in the existing IVR / keys dialogs.
 */
require_once __DIR__ . '/../../modules/destinations/DestinationRegistry.php';

use PBX\Destinations\DestinationRegistry;

class IvrCallFlowService
{
    /** Deeper chains are cut with a "continues" marker. */
    public const MAX_DEPTH = 6;

    /**
     * Everything the tree needs, read once for all IVRs on the page.
     *
     * @return array{ivrs: array, entries: array, time_conditions: array, announcements: array}
     */
    public static function loadData(): array
    {
        $db = getDB();
        $byId = function (string $sql) use ($db): array {
            $out = [];
            foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(string) $row['id']] = $row;
            }
            return $out;
        };
        $entries = [];
        foreach ($db->query('SELECT ivr_id, digit, dest_type, dest_id FROM pbx_ivr_entries ORDER BY digit ASC')->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $entries[(string) $e['ivr_id']][] = $e;
        }
        return [
            'ivrs' => $byId('SELECT * FROM pbx_ivrs'),
            'entries' => $entries,
            'time_conditions' => $byId('SELECT id, title, match_dest_type, match_dest_id, nomatch_dest_type, nomatch_dest_id, is_active FROM pbx_time_conditions'),
            'announcements' => $byId('SELECT id, title, audio_file, post_dest_type, post_dest_id FROM pbx_announcements'),
        ];
    }

    /**
     * Builds the tree for one destination.
     *
     * Node: ['kind' => ivr|time_condition|announcement|dest|loop|missing|more,
     *        'type', 'id', 'label', 'meta' => [...], 'children' => [['edge' => string, 'node' => Node], ...]]
     *
     * @param callable(string, string): ?string $labeler name of a plain destination (null = not found)
     */
    public static function build(string $type, string $id, array $data, callable $labeler, array $path = [], int $depth = 0): array
    {
        $type = $type !== '' ? $type : 'hangup';
        $key = $type . ':' . $id;
        $node = ['kind' => 'dest', 'type' => $type, 'id' => $id, 'label' => '', 'meta' => [], 'children' => []];

        $expandable = ['ivr' => 'ivrs', 'time_condition' => 'time_conditions', 'announcement' => 'announcements'];
        if (isset($expandable[$type])) {
            $row = $data[$expandable[$type]][$id] ?? null;
            if ($row === null) {
                return ['kind' => 'missing'] + $node;
            }
            $node['label'] = (string) $row['title'];
            if (in_array($key, $path, true)) {
                // A menu that leads back to itself (e.g. "9 = repeat"): no endless tree.
                return ['kind' => 'loop'] + $node;
            }
            if ($depth >= self::MAX_DEPTH) {
                return ['kind' => 'more'] + $node;
            }
            $path[] = $key;
            $next = fn(string $t, string $i) => self::build($t, $i, $data, $labeler, $path, $depth + 1);

            if ($type === 'ivr') {
                $node['kind'] = 'ivr';
                $node['meta'] = [
                    'prompt' => (string) ($row['prompt_file'] ?? ''),
                    'timeout' => (int) ($row['timeout_seconds'] ?? 10),
                    'direct_dial' => !empty($row['allow_direct_dial']),
                    'active' => !isset($row['is_active']) || (int) $row['is_active'] === 1,
                ];
                foreach ($data['entries'][$id] ?? [] as $e) {
                    $node['children'][] = ['edge' => 'key:' . $e['digit'], 'node' => $next((string) $e['dest_type'], (string) $e['dest_id'])];
                }
                $node['children'][] = ['edge' => 'timeout:' . $node['meta']['timeout'], 'node' => $next((string) ($row['timeout_dest_type'] ?? ''), (string) ($row['timeout_dest_id'] ?? ''))];
                $node['children'][] = ['edge' => 'invalid', 'node' => $next((string) ($row['invalid_dest_type'] ?? 'hangup'), (string) ($row['invalid_dest_id'] ?? ''))];
            } elseif ($type === 'time_condition') {
                $node['kind'] = 'time_condition';
                $node['children'][] = ['edge' => 'match', 'node' => $next((string) $row['match_dest_type'], (string) $row['match_dest_id'])];
                $node['children'][] = ['edge' => 'nomatch', 'node' => $next((string) $row['nomatch_dest_type'], (string) $row['nomatch_dest_id'])];
            } else {
                $node['kind'] = 'announcement';
                $node['meta'] = ['prompt' => (string) ($row['audio_file'] ?? '')];
                $node['children'][] = ['edge' => 'after', 'node' => $next((string) ($row['post_dest_type'] ?? 'hangup'), (string) ($row['post_dest_id'] ?? ''))];
            }
            return $node;
        }

        if ($type === 'hangup') {
            return $node;
        }
        $label = $labeler($type, $id);
        if ($label === null) {
            return ['kind' => 'missing'] + $node;
        }
        $node['label'] = $label;
        return $node;
    }

    /** Destination names through the registry (the same names as the badges). */
    public static function registryLabeler(): callable
    {
        $cache = [];
        return function (string $type, string $id) use (&$cache): ?string {
            return DestinationRegistry::resolveLabel($type, $id, $cache);
        };
    }

    public static function render(array $tree): string
    {
        return '<ul class="ivr-flow">' . self::renderItem(null, $tree) . '</ul>';
    }

    private static function renderItem(?string $edge, array $node): string
    {
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $out = '<li>';
        if ($edge !== null) {
            $out .= '<span class="ivr-flow-edge">' . $h(self::edgeLabel($edge)) . '</span>';
        }

        $mod = DestinationRegistry::getModule($node['type']);
        $typeName = $mod ? $mod->getName() : $node['type'];
        $badge = DestinationRegistry::badgeClassFor($node['type']);

        switch ($node['kind']) {
            case 'ivr':
                $meta = $node['meta'];
                $out .= '<div class="ivr-flow-node ivr-flow-ivr' . ($meta['active'] ? '' : ' is-inactive') . '">'
                    . '<div class="ivr-flow-title"><i class="fas fa-microphone-alt"></i> ' . $h($node['label']) . '</div>'
                    . '<div class="ivr-flow-meta"><i class="fas fa-volume-up"></i> ' . $h($meta['prompt']) . '</div>'
                    . ($meta['direct_dial'] ? '<div class="ivr-flow-meta"><i class="fas fa-phone-volume"></i> ' . $h(t('ivr_flow.direct_dial')) . '</div>' : '')
                    . (!$meta['active'] ? '<div class="ivr-flow-meta u-danger">' . $h(t('ivr_flow.inactive')) . '</div>' : '')
                    . '</div>';
                break;
            case 'time_condition':
                $out .= '<div class="ivr-flow-node"><span class="badge ' . $badge . '">' . $h($typeName) . '</span> ' . $h($node['label']) . '</div>';
                break;
            case 'announcement':
                $out .= '<div class="ivr-flow-node"><span class="badge ' . $badge . '">' . $h($typeName) . '</span> ' . $h($node['label'])
                    . '<div class="ivr-flow-meta"><i class="fas fa-volume-up"></i> ' . $h($node['meta']['prompt']) . '</div></div>';
                break;
            case 'loop':
                $out .= '<div class="ivr-flow-node ivr-flow-leaf"><i class="fas fa-redo"></i> ' . $h(sprintf(t('ivr_flow.loop'), $node['label'])) . '</div>';
                break;
            case 'more':
                $out .= '<div class="ivr-flow-node ivr-flow-leaf"><span class="badge ' . $badge . '">' . $h($typeName) . '</span> ' . $h($node['label']) . ' …</div>';
                break;
            case 'missing':
                $out .= '<div class="ivr-flow-node ivr-flow-leaf"><span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> '
                    . $h($typeName) . ' ' . $h(t('ivr.target_not_found')) . '</span></div>';
                break;
            default:
                $text = $node['type'] === 'hangup' ? $h($typeName) : '<span class="badge ' . $badge . '">' . $h($typeName) . '</span> ' . $h($node['label']);
                $out .= '<div class="ivr-flow-node ivr-flow-leaf">' . $text . '</div>';
        }

        if (!empty($node['children'])) {
            $out .= '<ul>';
            foreach ($node['children'] as $child) {
                $out .= self::renderItem($child['edge'], $child['node']);
            }
            $out .= '</ul>';
        }
        return $out . '</li>';
    }

    private static function edgeLabel(string $edge): string
    {
        if (str_starts_with($edge, 'key:')) {
            return t('ivr.key_label') . ' ' . substr($edge, 4);
        }
        if (str_starts_with($edge, 'timeout:')) {
            return sprintf(t('ivr_flow.timeout'), substr($edge, 8));
        }
        return match ($edge) {
            'invalid' => t('ivr_flow.invalid'),
            'match' => t('ivr_flow.match'),
            'nomatch' => t('ivr_flow.nomatch'),
            'after' => t('ivr_flow.after'),
            default => $edge,
        };
    }
}
