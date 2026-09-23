<?php
/**
 * Dahili Hedef Numaraları — merkezi kayıt defteri ve çakışma doğrulaması.
 *
 * Bu dosya "hangi varlıklar numara taşıyabilir" ve "bu numara serbest mi"
 * sorularını bilir. Dialplan üretimini BİLMEZ — o src/sync/SyncInternalNumbers.php'de.
 * Bölünmenin sebebi, üreteç tarafının test edilebilir saf bir fonksiyona
 * (buildInternalNumbersConf) indirgenebilmesi.
 *
 * YENİ BİR VARLIK TİPİNE NUMARA EKLENECEKSE: yalnızca INTERNAL_NUMBER_SOURCES'a
 * bir satır eklenir. Doğrulama, üretim, panel listesi ve duman testi kuralı
 * hepsi buradan okur.
 */

require_once __DIR__ . '/../config.php';

/**
 * dest_type / dest_col: buildDestinationLines($dest_type, $dest_id) çağrısında
 * kullanılacak değerler.
 *
 * DİKKAT — 'hangup' satırındaki dest_col: buildDestinationLines()'ın 'hangup'
 * dalı dest_id'yi `pbx_hangup_actions.action_key` olarak arar
 * ("WHERE ha.action_key = ?"), sayısal id olarak DEĞİL. Buraya 'id' yazılırsa
 * sorgu hiçbir satır bulmaz ve her çağrı sessizce düz Hangup() olur —
 * seçilen "Meşgul Tonu"/"Şebeke Meşgul" davranışı ve anonsu kaybolur.
 */
const INTERNAL_NUMBER_SOURCES = [
    'ivr' => [
        'table' => 'pbx_ivrs', 'label_col' => 'title',
        'dest_type' => 'ivr', 'dest_col' => 'id',
        'ui' => 'IVR Menüsü',
    ],
    'queue' => [
        'table' => 'pbx_queues', 'label_col' => 'title',
        'dest_type' => 'queue', 'dest_col' => 'id',
        'ui' => 'Kuyruk',
    ],
    'time_condition' => [
        'table' => 'pbx_time_conditions', 'label_col' => 'title',
        'dest_type' => 'time_condition', 'dest_col' => 'id',
        'ui' => 'Zaman Koşulu',
    ],
    'announcement' => [
        'table' => 'pbx_announcements', 'label_col' => 'title',
        'dest_type' => 'announcement', 'dest_col' => 'id',
        'ui' => 'Anons',
    ],
    'ring_group' => [
        'table' => 'pbx_ring_groups', 'label_col' => 'name',
        'dest_type' => 'ring_group', 'dest_col' => 'id',
        'ui' => 'Çalma Grubu',
    ],
    'conference' => [
        'table' => 'pbx_conferences', 'label_col' => 'title',
        'dest_type' => 'conference', 'dest_col' => 'id',
        'ui' => 'Konferans Odası',
    ],
    'hangup' => [
        'table' => 'pbx_hangup_actions', 'label_col' => 'title',
        'dest_type' => 'hangup', 'dest_col' => 'action_key',
        'ui' => 'Çağrı Sonlandırma',
    ],
];

/** Kullanıcı girdisinden yalnızca rakamları bırakır. */
function internalNumberSanitize($raw): string
{
    return preg_replace('/[^0-9]/', '', trim((string) $raw));
}

/**
 * Aktif ve numarası dolu TÜM varlıkları tek listede döndürür.
 * Sıralama numaraya göre — üretilen conf dosyasının diff'i sabit kalsın diye.
 */
function internalNumberEntries(): array
{
    $db = getDB();
    $out = [];

    foreach (INTERNAL_NUMBER_SOURCES as $key => $src) {
        $sql = "SELECT id, internal_number, {$src['label_col']} AS label, {$src['dest_col']} AS dest_id
                FROM {$src['table']}
                WHERE is_active = 1 AND internal_number IS NOT NULL AND internal_number <> ''";
        foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $num = internalNumberSanitize($row['internal_number']);
            if ($num === '') continue;
            $out[] = [
                'number'    => $num,
                'source'    => $key,
                'dest_type' => $src['dest_type'],
                'dest_id'   => (string) $row['dest_id'],
                'label'     => (string) $row['label'],
                'row_id'    => (int) $row['id'],
            ];
        }
    }

    usort($out, fn($a, $b) => strcmp($a['number'], $b['number']));
    return $out;
}

/**
 * Numara birileri tarafından kullanılıyorsa "kim" bilgisini insan okunur
 * biçimde döndürür, boştaysa null.
 *
 * $skipSource/$skipId: kaydın KENDİ numarasını çakışma saymamak için
 * (düzenleme sırasında numara değiştirilmeden kaydedilirse hata vermemeli).
 */
function internalNumberOwner(string $number, ?string $skipSource = null, int $skipId = 0): ?string
{
    $number = internalNumberSanitize($number);
    if ($number === '') return null;
    $db = getDB();

    $u = $db->prepare("SELECT full_name, extension_type FROM sys_users WHERE extension = ? LIMIT 1");
    $u->execute([$number]);
    if ($row = $u->fetch(PDO::FETCH_ASSOC)) {
        $tip = ($row['extension_type'] === 'fax') ? 'faks dahilisi' : 'dahili';
        return "{$tip}: " . $row['full_name'];
    }

    $f = $db->prepare("SELECT title FROM pbx_feature_codes WHERE code = ? LIMIT 1");
    $f->execute([$number]);
    if ($title = $f->fetchColumn()) {
        return "özellik kodu: {$title}";
    }

    foreach (INTERNAL_NUMBER_SOURCES as $key => $src) {
        $sql = "SELECT {$src['label_col']} AS label FROM {$src['table']} WHERE internal_number = ?";
        $params = [$number];
        if ($key === $skipSource && $skipId > 0) {
            $sql .= " AND id <> ?";
            $params[] = $skipId;
        }
        $st = $db->prepare($sql . " LIMIT 1");
        $st->execute($params);
        if ($label = $st->fetchColumn()) {
            return $src['ui'] . ": " . $label;
        }
    }

    return null;
}

/**
 * Numara kaydedilebilir değilse \Exception fırlatır. Boş numara serbesttir
 * (alan isteğe bağlı) ve sessizce geçer.
 *
 * Uzunluk sınırı: alt sınır 2, çünkü tek haneli bir numara IVR tuş
 * eşleşmeleriyle ve acil servis kısayollarıyla karışır; üst sınır 6, çünkü
 * kolon VARCHAR(10) ve 7+ hane dış numara alanına girer.
 */
function assertInternalNumberAvailable(string $number, string $sourceKey, int $ownerId): void
{
    $number = internalNumberSanitize($number);
    if ($number === '') return;

    $len = strlen($number);
    if ($len < 2 || $len > 6) {
        throw new \Exception("Dahili hedef numarası 2-6 hane arasında olmalıdır (girilen: {$number}).");
    }

    $owner = internalNumberOwner($number, $sourceKey, $ownerId);
    if ($owner !== null) {
        throw new \Exception("{$number} numarası zaten kullanımda ({$owner}). Lütfen başka bir numara seçin.");
    }
}

/**
 * Asterisk numara desenini ("_[4-9]XXX") PCRE'ye çevirir.
 *
 * Yalnızca giden rota desenlerinde gerçekten kullanılan alt küme desteklenir:
 * X Z N nokta ünlem, köşeli parantez aralığı ve düz rakamlar. Desteklenmeyen
 * bir karakterle karşılaşılırsa null döner ve çağıran taraf o deseni ATLAR —
 * yanlış alarm vermek, sessiz kalmaktan daha kötü.
 */
function asteriskPatternToRegex(string $pattern): ?string
{
    $p = trim($pattern);
    if ($p === '') return null;

    if ($p[0] !== '_') {
        return '/^' . preg_quote($p, '/') . '$/';
    }

    $p = substr($p, 1);
    $re = '';
    $len = strlen($p);
    for ($i = 0; $i < $len; $i++) {
        $c = $p[$i];
        if ($c === 'X')            { $re .= '[0-9]'; }
        elseif ($c === 'Z')        { $re .= '[1-9]'; }
        elseif ($c === 'N')        { $re .= '[2-9]'; }
        elseif ($c === '.')        { $re .= '.+'; }
        elseif ($c === '!')        { $re .= '.*'; }
        elseif ($c === '-')        { /* Asterisk desenlerinde tire yok sayılır */ }
        elseif (ctype_digit($c))   { $re .= $c; }
        elseif ($c === '[') {
            $end = strpos($p, ']', $i);
            if ($end === false) return null;
            $ic = substr($p, $i + 1, $end - $i - 1);
            if (!preg_match('/^[0-9\-]+$/', $ic)) return null;
            $re .= '[' . $ic . ']';
            $i = $end;
        }
        else { return null; }
    }

    return '/^' . $re . '$/';
}

/**
 * Numara aktif bir giden rota deseniyle de eşleşiyorsa uyarı metni döndürür.
 * ENGELLEMEZ — Asterisk'in eşleşme sırası zaten dahili hedefi öne alıyor
 * (from-internal-pbx-ortak kendi include'unu outbound'dan önce arar), ama
 * admin'in bu numaranın artık santrale çıkmayacağını bilmesi gerekir.
 */
function internalNumberRouteWarning(string $number): ?string
{
    $number = internalNumberSanitize($number);
    if ($number === '') return null;

    $rows = getDB()->query(
        "SELECT route_name, match_pattern FROM pbx_outbound_routes WHERE is_active = 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $re = asteriskPatternToRegex((string) $r['match_pattern']);
        if ($re === null) continue;
        if (preg_match($re, $number) === 1) {
            return "{$number} numarası '" . $r['route_name'] . "' giden rotasının deseni ("
                 . $r['match_pattern'] . ") ile de eşleşiyor. Dahili hedef önce çalışır, "
                 . "yani bu numara artık dış hatta çıkmayacak.";
        }
    }

    return null;
}
