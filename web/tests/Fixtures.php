<?php

/**
 * A known minimal data set for the tests.
 *
 * Every test class calls Fixtures::load() in setUp() to start from a clean,
 * PREDICTABLE state.
 *
 * Works ONLY on asterisk_test — the safety lock in tests/bootstrap.php
 * guarantees that (tests do not start at all with another DB_NAME). Still,
 * there is a second, defensive check here: run in production by mistake,
 * TRUNCATE would delete real data.
 */
final class Fixtures
{
    public const TRUNK_NAME = 'testtrunk';
    public const QUEUE_NAME = 'testqueue';
    public const DID_NUMBER = '9990001';

    /**
     * Tables the fixtures touch — load() empties them.
     *
     * `sip` MUST BE ON THE LIST: part of the trunk settings lives in this
     * key-value table and SyncTrunks.php reads it BEFORE pbx_trunks (line 49:
     * sip_map['t38_udptl'] wins if present). Without clearing it, a value one
     * test writes pollutes the next — exactly that happened on 2026-09-01: the
     * test that set t38_support=0 still saw t38_udptl=yes because of the
     * sip.t38_udptl='yes' left by the previous test.
     */
    private const TABLES = [
        'pbx_dids',
        'pbx_ivrs',
        'pbx_ivr_entries',
        'pbx_queues',
        'pbx_trunks',
        'pbx_time_conditions',
        'pbx_announcements',
        'pbx_hangup_actions',
        'pbx_outbound_routes',
        'pbx_phones',
        'pbx_phones_waiting',
        'pbx_phone_discoveries',
        'sip',
        'sys_pending_sync',
    ];

    public static function load(): void
    {
        self::productionGuard();

        $db = getDB();

        // Release any uncommitted locks from a previous test that aborted
        if ($db->inTransaction()) {
            try {
                $db->rollBack();
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TABLES as $t) {
            // DELETE FROM is a DML operation that doesn't trigger DDL metadata lock timeouts
            // or implicit commits, unlike TRUNCATE TABLE.
            $db->exec("DELETE FROM `{$t}`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');

        // Trunk: required columns trunk_name, title, ip_address.
        // ON DUPLICATE KEY UPDATE ensures idempotence if any stale rows survive.
        $db->prepare(
            'INSERT INTO pbx_trunks
                (trunk_name, title, ip_address, port, codecs, is_active, t38_support, send_caller_name)
             VALUES (?, ?, ?, ?, ?, 1, 1, 0)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                ip_address = VALUES(ip_address),
                port = VALUES(port),
                codecs = VALUES(codecs),
                is_active = 1,
                t38_support = 1,
                send_caller_name = 0'
        )->execute([self::TRUNK_NAME, 'Test Trunk', '192.0.2.10', 5060, 'alaw,ulaw']);

        // Queue: required columns queue_name, title.
        $db->prepare(
            'INSERT INTO pbx_queues (queue_name, title, strategy, is_active)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                strategy = VALUES(strategy),
                is_active = 1'
        )->execute([self::QUEUE_NAME, 'Test Queue', 'ringall']);

        // IVR: required column title.
        $db->prepare('INSERT INTO pbx_ivrs (title) VALUES (?)')->execute(['Test IVR']);

        // DID: required columns did_number, title, dest_type, dest_id.
        $queueId = (int) $db->query(
            'SELECT id FROM pbx_queues WHERE queue_name = ' . $db->quote(self::QUEUE_NAME)
        )->fetchColumn();
        $db->prepare(
            'INSERT INTO pbx_dids (did_number, title, dest_type, dest_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                dest_type = VALUES(dest_type),
                dest_id = VALUES(dest_id)'
        )->execute([self::DID_NUMBER, 'Test DID', 'queue', $queueId]);

        // Two entities with an internal destination number — the
        // SyncInternalNumbers and InternalNumberTest tests rely on them.
        $db->prepare('UPDATE pbx_ivrs SET internal_number = ? WHERE title = ?')
           ->execute(['1010', 'Test IVR']);
        $db->prepare(
            'INSERT INTO pbx_hangup_actions (action_key, title, action_type, internal_number, is_active)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                action_type = VALUES(action_type),
                internal_number = VALUES(internal_number),
                is_active = 1'
        )->execute(['testbusy', 'Test Mesgul', 'busy', '1020']);

        // Outbound route: required columns route_name, match_pattern, trunks_json
        $db->prepare(
            'INSERT INTO pbx_outbound_routes (route_name, match_pattern, trunks_json, is_internal, route_group, is_active)
             VALUES (?, ?, ?, 0, 1, 1)'
        )->execute(['Test Outbound', '_05XXXXXXXXX', json_encode([['trunk_name' => self::TRUNK_NAME]])]);
    }

    /**
     * Must NEVER run on the production database — TRUNCATE cannot be undone.
     * bootstrap.php already prevents it; this is the second line of defence.
     */
    private static function productionGuard(): void
    {
        if (DB_NAME !== 'asterisk_test') {
            throw new RuntimeException(
                "Fixtures run ONLY on asterisk_test, current DB_NAME='" . DB_NAME . "'"
            );
        }
    }
}
