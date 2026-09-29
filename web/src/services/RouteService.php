<?php
/**
 * Inbound & Outbound Route Service
 */

class RouteService {
    public static function saveDIDRoute($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $route_id = intval($data['route_id'] ?? 0);
            $did_number = preg_replace('/[^0-9]/', '', trim($data['did_number'] ?? ''));
            $title = trim($data['title'] ?? '');
            $dest_type = sanitizeDestType($data['dest_type'] ?? 'time_condition');
            $dest_id = trim($data['dest_id'] ?? '');
            $record_call = isset($data['record_call']) ? 1 : 0;
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;
            $language = trim($data['language'] ?? '');
            if ($language !== '' && !in_array($language, getAvailableLanguages(), true)) {
                $language = '';
            }

            if (empty($did_number) || empty($title)) {
                throw new \Exception("DID Numarası ve Tanımı zorunludur!");
            }

            $is_new = ($route_id <= 0);
            DBHelper::save('pbx_dids', [
                'id' => $route_id,
                'did_number' => $did_number,
                'title' => $title,
                'dest_type' => $dest_type,
                'dest_id' => $dest_id,
                'record_call' => $record_call,
                'language' => $language ?: null,
                'is_active' => $is_active
            ]);

            markPendingSync('inbound_dialplan', 'did_route', $did_number, "Gelen Rota: {$did_number} ({$title})", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            return "Gelen Rota '{$did_number}' kaydedildi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    public static function deleteDIDRoute($route_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($route_id) {
            $route_id = intval($route_id);
            $d_row = DBHelper::fetchOne("SELECT did_number, title FROM pbx_dids WHERE id = ?", [$route_id]);
            DBHelper::delete('pbx_dids', 'id', $route_id);
            markPendingSync('inbound_dialplan', 'did_route', $d_row['did_number'] ?? ('id_' . $route_id), "Gelen Rota: " . ($d_row['did_number'] ?? $route_id) . " (silindi)", 'delete', $_SESSION['user_id'] ?? null);
            return "Gelen Rota silindi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    public static function saveOutboundRoute($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $route_id = intval($data['route_id'] ?? 0);
            $route_name = trim($data['route_name'] ?? '');
            $match_pattern = preg_replace('/[^0-9NXZnxz.\[\]_!*#-]/', '', trim($data['match_pattern'] ?? ''));
            $prepend = preg_replace('/[^0-9]/', '', trim($data['prepend'] ?? ''));
            $append = preg_replace('/[^0-9]/', '', trim($data['append'] ?? ''));
            $strip_front = intval($data['strip_front'] ?? 0);
            $strip_back = intval($data['strip_back'] ?? 0);
            $is_internal = isset($data['is_internal']) ? 1 : 0;
            // Giden rota grubu: bu rotayi hangi kullanici grubu kullanabilir.
            // 1 varsayilan; gecersiz deger gelirse 1'e dusuluyor ki rota
            // erisilemez bir gruba dusup sessizce kaybolmasin.
            $route_group = max(1, min(99, intval($data['route_group'] ?? 1)));
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

            // Dış hatlar: birincil/yedek ayrımı yok — tek tek eklenen, sırasıyla denenen
            // bir liste. Her satırın kendi Caller ID maskeleme değeri olabilir.
            // trunk_name yalnızca pbx_trunks'ta gerçekten var olan isimlerle sınırlanır —
            // hem işlevsel doğruluk hem de dialplan enjeksiyonuna karşı savunma içindir.
            $valid_trunk_names = array_column(getDB()->query("SELECT trunk_name FROM pbx_trunks")->fetchAll(PDO::FETCH_ASSOC), 'trunk_name');
            $trunk_names = (array)($data['trunk_name'] ?? []);
            $trunk_cids = (array)($data['trunk_cid'] ?? []);
            $trunks = [];
            foreach ($trunk_names as $i => $tn) {
                $tn = trim($tn);
                if ($tn === '' || !in_array($tn, $valid_trunk_names, true)) continue;
                $trunks[] = ['trunk_name' => $tn, 'callerid_override' => preg_replace('/[^0-9]/', '', trim($trunk_cids[$i] ?? ''))];
            }

            if (empty($route_name) || empty($match_pattern)) {
                throw new \Exception("Rota ismi ve eşleşme deseni zorunludur!");
            }
            if (empty($trunks)) {
                throw new \Exception("En az bir dış hat eklemelisiniz!");
            }
            // Asterisk keeps only the first of two identical patterns in a context.
            $dup = DBHelper::fetchOne(
                "SELECT route_name FROM pbx_outbound_routes WHERE match_pattern = ? AND route_group = ? AND id <> ?",
                [$match_pattern, $route_group, $route_id]
            );
            if ($dup) {
                throw new \Exception("'{$match_pattern}' deseni bu grupta zaten '{$dup['route_name']}' rotasında kullanılıyor — farklı bir desen girin.");
            }

            $is_new = ($route_id <= 0);
            $saved_id = DBHelper::save('pbx_outbound_routes', [
                'id' => $route_id,
                'route_name' => $route_name,
                'match_pattern' => $match_pattern,
                'prepend' => $prepend,
                'append' => $append,
                'strip_front' => $strip_front,
                'strip_back' => $strip_back,
                'trunks_json' => json_encode($trunks),
                'is_internal' => $is_internal,
                'route_group' => $route_group,
                'is_active' => $is_active
            ]);
            if ($is_new) {
                getDB()->prepare("UPDATE pbx_outbound_routes SET sort_order = (SELECT m FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS m FROM pbx_outbound_routes) x) WHERE id = ?")->execute([$saved_id]);
            }

            markPendingSync('outbound_dialplan', 'outbound_route', $route_name, "Giden Rota: {$route_name}", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            markPendingSync('ivrs', 'outbound_route', $route_name, "Giden Rota: {$route_name}", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            return "Giden Rota '{$route_name}' kaydedildi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }

    public static function deleteOutboundRoute($route_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function() use ($route_id) {
            $route_id = intval($route_id);
            $route_name = DBHelper::fetchColumn("SELECT route_name FROM pbx_outbound_routes WHERE id = ?", [$route_id]);
            DBHelper::delete('pbx_outbound_routes', 'id', $route_id);
            markPendingSync('outbound_dialplan', 'outbound_route', $route_name ?: ('id_' . $route_id), "Giden Rota: " . ($route_name ?: $route_id) . " (silindi)", 'delete', $_SESSION['user_id'] ?? null);
            markPendingSync('ivrs', 'outbound_route', $route_name ?: ('id_' . $route_id), "Giden Rota: " . ($route_name ?: $route_id) . " (silindi)", 'delete', $_SESSION['user_id'] ?? null);
            return "Giden Rota silindi! Etkili olması için Uygula sayfasından gönderin.";
        });
    }
}
