<?php
/**
 * Asterisk CLI & Reload Execution Helper
 */

class AsteriskHelper {
    /**
     * Safely execute an Asterisk CLI command via asterisk -rx.
     *
     * ÖNEMLİ (2026-08-24 incelemesinde bulundu, canlıda doğrulandı):
     * `asterisk -rx` KOMUTUN KENDİSİ BAŞARISIZ OLSA BİLE HER ZAMAN exit code 0
     * döner — `asterisk -rx "olmayan bir komut"` bile 0 ile çıkar. Yani $ret
     * hiçbir zaman gerçek başarı/başarısızlık göstergesi olmamış (önceki kod
     * `'success' => ($ret === 0)` yazıyordu — bu HER ZAMAN true demekle
     * eşdeğerdi, syncXxx() fonksiyonları reload'un GERÇEKTEN çalıştığını hiç
     * bilmiyordu). $ret artık kontrol edilmiyor — çıktı metninde bilinen hata
     * kalıplarını arayan bir HEURİSTİK kullanılıyor. Bu KESİN değil (Asterisk
     * CLI çıktı formatı resmi/stabil bir API değil) ama "her zaman başarılı
     * say" varsayımından kesinlikle daha iyi — ve çıktının TAMAMI her durumda
     * çağırana döndürülüyor, admin isterse ham metni görebilir.
     */
    public static function execCLI($command) {
        // TEST KİLİDİ: otomatik testler canlı Asterisk'e ASLA komut göndermemeli.
        // syncAllTrunks() gibi üreteçler writeConfWithRollback() içinden reload
        // tetikliyor; bu kilit olmadan birim testleri üretimdeki Asterisk'i
        // reload ederdi. tests/bootstrap.php bu değişkeni kuruyor; üretimde hiç
        // tanımlı olmadığı için normal akış hiç değişmez.
        if (getenv('AIPBX_NO_ASTERISK') === '1') {
            return ['success' => true, 'output' => '[test-modu] atlandi: ' . $command];
        }

        $cmd = 'asterisk -rx ' . escapeshellarg($command);
        exec($cmd . ' 2>&1', $out, $ret);
        $output = implode("\n", $out);
        return [
            'success' => !self::looksLikeCliFailure($output),
            'output' => $output
        ];
    }

    /**
     * `asterisk -rx` çıktısında bilinen hata kalıplarını arayan paylaşılan
     * heuristik — execCLI() ve DashboardController'ın manuel `sudo asterisk
     * -rx 'core reload'` çağrısı (farklı bir sudo/shell_exec yolu kullanıyor,
     * execCLI()'yi çağırmıyor ama AYNI çıktı formatını üretiyor) aynı kontrolü
     * paylaşsın diye ayrı bir metoda çıkarıldı.
     */
    public static function looksLikeCliFailure($output) {
        return (bool) preg_match('/no such command|not found|unable to|error|failed|invalid|usage:/i', (string) $output);
    }

    /**
     * Bir veya daha fazla execCLI() sonucunu ($success/$output içeren dizi)
     * kontrol eder, herhangi biri başarısızsa TÜM hata çıktılarını birleştiren
     * bir Exception fırlatır. syncXxx() fonksiyonları bunu her reload
     * çağrısından sonra kullanır — applyPendingSync()'in zaten var olan
     * try/catch'i bu Exception'ı yakalayıp o domain'i "başarısız" olarak
     * işaretler (satır pending listede kalır, admin ham Asterisk çıktısını
     * /pending-sync sayfasında görür).
     */
    public static function assertReloadsOk(array $results, $context) {
        $failures = [];
        foreach ($results as $r) {
            if (empty($r['success'])) {
                $failures[] = trim($r['output'] ?? '') ?: '(Asterisk boş/belirsiz bir yanıt döndürdü)';
            }
        }
        if (!empty($failures)) {
            throw new \Exception("{$context} sırasında Asterisk hata döndürdü: " . implode(' | ', $failures));
        }
    }

    /**
     * Reload PJSIP module
     */
    public static function reloadPJSIP() {
        return self::execCLI('pjsip reload');
    }

    /**
     * Reload Dialplan
     */
    public static function reloadDialplan() {
        return self::execCLI('dialplan reload');
    }

    /**
     * Reload Queues
     */
    public static function reloadQueue($queue = 'all') {
        return self::execCLI($queue === 'all' ? 'queue reload all' : "queue reload {$queue}");
    }

    /**
     * Reload Music on Hold
     */
    public static function reloadMOH() {
        return self::execCLI('moh reload');
    }

    /**
     * Reload RTP configuration (/etc/asterisk/rtp.conf)
     *
     * DİKKAT — kapsam sınırı: bu reload `strictrtp` gibi davranış ayarlarını
     * devreye alır, ancak `rtpstart`/`rtpend` port ARALIĞI değişikliği için
     * Asterisk TAM YENİDEN BAŞLATMA gerektirir (port havuzu modül yüklenirken
     * bir kez ayrılıyor). Kullanıcıya bu ayrım arayüzde ayrıca belirtiliyor.
     */
    public static function reloadRTP() {
        return self::execCLI('module reload res_rtp_asterisk.so');
    }

    /**
     * Reload UDPTL configuration (/etc/asterisk/udptl.conf)
     */
    public static function reloadUDPTL() {
        return self::execCLI('module reload udptl');
    }

    /**
     * Resolve the trunk to dial through when a specific outbound route isn't in play
     * (fax sending, the outbound dialplan's zero-routes-configured bootstrap fallback).
     * Reads the first active row from `pbx_trunks`. Returns null if no trunk is configured
     * at all — callers must handle that case explicitly (no trunk means no trunk; never
     * invent a name that doesn't exist in this installation).
     */
    public static function getPrimaryTrunkName() {
        try {
            $db = getDB();
            $row = $db->query("SELECT trunk_name FROM pbx_trunks WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch();
            return !empty($row['trunk_name']) ? $row['trunk_name'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get live PJSIP endpoint statuses (e.g. 3001-sip => Not in use, neco => Not in use).
     * 2026-08-19 düzeltmesi: `pjsip show endpoints` her satırı `<name>/<CID>` biçiminde basar
     * (ör. `3001-sip/3001`) ve durum "Not in use" gibi BİRDEN FAZLA kelime olabilir. Eski regex
     * yalnızca ilk kelimeyi ("Not") yakalıyor ve `/<CID>` sonekini anahtara dahil ediyordu — bu
     * yüzden `$statuses["3001"]` gibi çıplak-numara sorguları HİÇBİR ZAMAN eşleşmiyordu
     * (dual-endpoint'te gerçek anahtarlar `3001-sip`/`3001-webrtc`). Aşağıdaki regex hem `/<CID>`
     * sonekini atar hem tam durum metnini ("Not in use") yakalar.
     */
    public static function getPJSIPStatuses() {
        $res = self::execCLI("pjsip show endpoints");
        $output = $res['output'] ?? '';
        $statuses = [];

        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (preg_match('/^\s*Endpoint:\s+([^\s\/]+)(?:\/\S+)?\s+(.+?)\s+\d+\s+of\s+\S+\s*$/i', $line, $matches)) {
                $ep = trim($matches[1]);
                $st = trim($matches[2]);
                $statuses[$ep] = $st;
            }
        }
        return $statuses;
    }

    /**
     * Classifies a raw getPJSIPStatuses() value into one of: 'busy' (in use), 'idle' (not in
     * use / registered), 'down' (unavailable/unreachable), 'unknown' (endpoint exists but state
     * unrecognized), or null (no status at all — endpoint not found/never registered).
     */
    public static function classifyStatus($raw) {
        if (empty($raw)) return null;
        $r = strtolower($raw);
        if (strpos($r, 'not in use') !== false) return 'idle';
        if (strpos($r, 'in use') !== false) return 'busy';
        if (strpos($r, 'unavailable') !== false || strpos($r, 'unreachable') !== false) return 'down';
        return 'unknown';
    }
}

/**
 * Global Alias Function for Backwards Compatibility
 */
if (!function_exists('getAsteriskPJSIPStatuses')) {
    function getAsteriskPJSIPStatuses() {
        return AsteriskHelper::getPJSIPStatuses();
    }
}
