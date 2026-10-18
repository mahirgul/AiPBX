<?php
/**
 * AI → Assistant: what exists on THIS server, in the shape the planner
 * validates against (AssistantPlanner::context()). One query per list, the
 * same sources the portal's own dropdowns read:
 *  - extensions: sys_users (SIP/fax users with a number)
 *  - ring groups, queues: the same tables the pages use
 */
class AssistantPlanContext
{
    /** @return array<int, array{extension:string, name:string}> */
    public static function extensions(): array
    {
        $rows = getDB()->query(
            "SELECT extension, full_name FROM sys_users
             WHERE extension <> '' AND is_active = 1 ORDER BY extension ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['extension' => (string) $r['extension'], 'name' => (string) $r['full_name']];
        }
        return $out;
    }

    /** @return array<int, array{id:int, title:string}> */
    public static function ringGroups(): array
    {
        try {
            // The column is `name` (no `title` on this table); read it as title
            // so the planner's shared shape keeps working.
            $rows = getDB()->query('SELECT id, name AS title FROM pbx_ring_groups ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'], 'title' => (string) $r['title']];
        }
        return $out;
    }

    /** @return array<int, array{id:int, title:string}> */
    public static function queues(): array
    {
        try {
            $rows = getDB()->query('SELECT id, title FROM pbx_queues ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'], 'title' => (string) $r['title']];
        }
        return $out;
    }
}
