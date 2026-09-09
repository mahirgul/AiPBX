<?php
/**
 * Flexible Key-Value Queue Details Helper Module
 * Interacts with MariaDB `queues_details` table (`id`, `keyword`, `data`, `flags`)
 */

require_once __DIR__ . '/../config.php';

class QueueHelper {

    /**
     * Get raw details array for a queue name
     */
    public static function getDetails($q_name) {
        $db = getDB();
        $stmt = $db->prepare("SELECT keyword, data, flags FROM queues_details WHERE id = ? ORDER BY flags ASC, keyword ASC");
        $stmt->execute([$q_name]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get details as associative map ['keyword' => 'data']
     */
    public static function getDetailsMap($q_name) {
        $rows = self::getDetails($q_name);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['keyword']] = $r['data'];
        }
        return $map;
    }

    /**
     * Bulk upsert multiple queue details
     */
    public static function setDetails($q_name, array $pairs, $flags = 0) {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO queues_details (id, keyword, data, flags) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), flags = VALUES(flags)");
        foreach ($pairs as $key => $val) {
            if ($val === null) continue;
            $stmt->execute([$q_name, (string)$key, (string)$val, (int)$flags]);
        }
        return true;
    }

    /**
     * Delete all details for a queue
     */
    public static function deleteDetails($q_name) {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM queues_details WHERE id = ?");
        return $stmt->execute([$q_name]);
    }

    /**
     * Populate standard queue parameters into `queues_details`
     */
    public static function syncQueueToDetails(array $q) {
        $q_name = trim($q['queue_name'] ?? '');
        if (empty($q_name)) return false;

        $strategy = !empty($q['strategy']) ? $q['strategy'] : 'rrmemory';
        $timeout = intval($q['timeout'] ?? 15) ?: 15;
        $retry = intval($q['retry'] ?? 5) ?: 5;
        $wrapuptime = intval($q['wrapuptime'] ?? 10) ?: 10;
        $announce_frequency = intval($q['announce_frequency'] ?? 30) ?: 30;
        $announce_holdtime = !empty($q['announce_holdtime']) ? $q['announce_holdtime'] : 'yes';
        $ringinuse = ($q['ringinuse'] ?? 'no') === 'yes' ? 'yes' : 'no';
        $joinempty = ($q['joinempty'] ?? 'yes') === 'no' ? 'no' : 'yes';
        $leavewhenempty = ($q['leavewhenempty'] ?? 'no') === 'yes' ? 'yes' : 'no';
        $maxlen = intval($q['maxlen'] ?? 0);

        // monitor-type per-queue directive kasıtlı olarak burada yok: Asterisk 22
        // bunu sadece queues.conf [general] bölümünde kabul ediyor (bkz.
        // SyncQueues.php'deki global "monitor-type = MixMonitor" satırı),
        // per-queue section'da yazılırsa "Unknown keyword" uyarısı veriyor.
        $defaults = [
            'strategy' => $strategy,
            'timeout' => (string)$timeout,
            'retry' => (string)$retry,
            'wrapuptime' => (string)$wrapuptime,
            'autofill' => 'yes',
            'ringinuse' => $ringinuse,
            'announce-frequency' => (string)$announce_frequency,
            'announce-holdtime' => $announce_holdtime,
            'announce-position' => 'yes',
            'periodic-announce-frequency' => '45',
            // Asterisk'in varsayılan "queue-periodic-announce" dosyası Türkçe
            // ses paketinde yok (sadece en/es/fr/pr/pt_BR'de var) — bulunamayınca
            // Asterisk sessizce İngilizce'ye düşüyor. Zaten Türkçesi mevcut olan
            // youarenext+thankyou anonsları listeye verilince periyodik olarak
            // bunlar sırayla tekrarlanıyor (2026-08-31, kullanıcı isteği).
            'periodic-announce' => 'queue-youarenext,queue-thankyou',
            'monitor-format' => 'wav',
            'joinempty' => $joinempty,
            'leavewhenempty' => $leavewhenempty,
            'maxlen' => (string)$maxlen,
            'setinterfacevar' => 'yes',
            'setqueueentryvar' => 'yes',
            'setqueuevar' => 'yes'
        ];

        return self::setDetails($q_name, $defaults);
    }

    /**
     * Bir dahilinin bir kuyruğa DB'de ($members_json) atanmış olup olmadığını kontrol eder.
     * Canlı Asterisk üyeliğinden bağımsız — "bu kişi bu kuyrukta çalışabilir mi" sorusu.
     */
    public static function isAssignedMember($ext, $queue_name) {
        $db = getDB();
        $stmt = $db->prepare("SELECT members_json FROM pbx_queues WHERE queue_name = ? AND is_active = 1");
        $stmt->execute([$queue_name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $members = json_decode($row['members_json'] ?? '[]', true) ?: [];
        return in_array((string)$ext, array_map('strval', $members));
    }

    /**
     * Bir dahiliyi canlı olarak bir kuyruğa ekler/çıkarır (asterisk -rx "queue add/remove
     * member ..."). Hem web panelindeki "Kuyruğa Gir/Çık" butonu (api/cc_actions/queues.php)
     * hem telefon feature code'ları (*81/*80, bkz. feature_code_action.php) bu tek yeri kullanır.
     */
    public static function setMembership($ext, $queue_name, $join) {
        $ext = preg_replace('/[^0-9]/', '', (string)$ext);
        $queue_name = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$queue_name);
        if ($ext === '' || $queue_name === '') return false;

        if ($join) {
            @exec("asterisk -rx " . escapeshellarg("queue add member Local/$ext@from-internal-pbx/n to $queue_name penalty 0 as \"Temsilci $ext\" state_interface hint:$ext@from-internal-pbx"));
            @exec("asterisk -rx " . escapeshellarg("queue unpause member Local/$ext@from-internal-pbx/n queue $queue_name"));
        } else {
            @exec("asterisk -rx " . escapeshellarg("queue remove member Local/$ext@from-internal-pbx/n from $queue_name"));
            @exec("asterisk -rx " . escapeshellarg("queue remove member PJSIP/$ext from $queue_name"));
        }
        return true;
    }
}
