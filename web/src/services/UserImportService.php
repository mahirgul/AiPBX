<?php
require_once __DIR__ . '/UserService.php';
require_once dirname(__DIR__) . '/internal_numbers.php';

/**
 * CSV ile toplu kullanıcı ekleme.
 *
 * Akış: parse() → validate() (önizleme, hiçbir şey yazılmaz) → import().
 * import() her satır için UserService::saveUser()'ı çağırır; böylece tek tek
 * ekleme ile AYNI kurallar (rol kontrolü, dahili/PJSIP oluşturma, Asterisk
 * senkron işaretleri, davet e-postası, otomatik şifre) uygulanır.
 */
class UserImportService
{
    const MAX_ROWS = 1000;
    const MAX_BYTES = 1048576; // 1 MB

    /** Kabul edilen sütun adları (küçük harf, boşluk/tire → alt çizgi) → alan. */
    const HEADER_ALIASES = [
        'username' => 'username', 'kullanici_adi' => 'username', 'kullanıcı_adı' => 'username', 'kullanici' => 'username',
        'full_name' => 'full_name', 'ad_soyad' => 'full_name', 'adsoyad' => 'full_name', 'name' => 'full_name', 'isim' => 'full_name',
        'email' => 'email', 'e_mail' => 'email', 'eposta' => 'email', 'e_posta' => 'email',
        'extension' => 'extension', 'dahili' => 'extension', 'dahili_no' => 'extension', 'ext' => 'extension',
        'role' => 'role', 'rol' => 'role',
        'password' => 'password', 'sifre' => 'password', 'şifre' => 'password',
    ];

    /** Örnek şablon (UTF-8 BOM'lu, Excel'de doğru açılsın diye noktalı virgüllü). */
    public static function templateCsv(): string
    {
        return "\xEF\xBB\xBF" . "kullanici_adi;ad_soyad;eposta;dahili;rol;sifre\r\n"
            . "ahmet.yilmaz;Ahmet Yılmaz;ahmet.yilmaz@example.com;2001;cc_agent;\r\n"
            . "ayse.demir;Ayşe Demir;;2002;cc_agent;\r\n";
    }

    /**
     * @return array{success: bool, rows?: array<int, array>, error?: string}
     *   rows: [satır_no => ['username'=>..., 'full_name'=>..., ...]]
     */
    public static function parse(string $content): array
    {
        if ($content === '' || strlen($content) > self::MAX_BYTES) {
            return ['success' => false, 'error' => 'Dosya boş veya 1 MB sınırını aşıyor.'];
        }
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = substr($content, 3);
        }
        // Excel'in Türkçe "CSV (virgülle ayrılmış)" kaydı Windows-1254 üretir.
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1254');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $firstLine = strtok($content, "\n");
        $delimiter = ',';
        $best = -1;
        foreach ([';', ',', "\t"] as $d) {
            $n = substr_count((string) $firstLine, $d);
            if ($n > $best) { $best = $n; $delimiter = $d; }
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);

        $header = fgetcsv($fh, 0, $delimiter, '"', '');
        if (!$header) {
            return ['success' => false, 'error' => 'Başlık satırı okunamadı.'];
        }
        $map = [];
        foreach ($header as $i => $h) {
            $key = mb_strtolower(trim((string) $h));
            $key = preg_replace('/[\s\-]+/u', '_', $key);
            if (isset(self::HEADER_ALIASES[$key])) {
                $map[$i] = self::HEADER_ALIASES[$key];
            }
        }
        if (!in_array('username', $map, true) || !in_array('full_name', $map, true)) {
            return ['success' => false, 'error' => 'Başlıkta en az "kullanici_adi" ve "ad_soyad" sütunları olmalı (şablonu indirip kullanın).'];
        }

        $rows = [];
        $lineNo = 1;
        while (($cols = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $lineNo++;
            if ($cols === [null] || implode('', array_map('trim', $cols)) === '') {
                continue; // boş satır
            }
            $row = ['username' => '', 'full_name' => '', 'email' => '', 'extension' => '', 'role' => '', 'password' => ''];
            foreach ($map as $i => $field) {
                $row[$field] = trim((string) ($cols[$i] ?? ''));
            }
            $rows[$lineNo] = $row;
            if (count($rows) > self::MAX_ROWS) {
                fclose($fh);
                return ['success' => false, 'error' => 'Bir seferde en fazla ' . self::MAX_ROWS . ' kullanıcı içe aktarılabilir.'];
            }
        }
        fclose($fh);

        if (!$rows) {
            return ['success' => false, 'error' => 'Dosyada kullanıcı satırı bulunamadı.'];
        }
        return ['success' => true, 'rows' => $rows];
    }

    /**
     * Hiçbir şey yazmadan satırları doğrular.
     *
     * @return array<int, array{row: array, errors: string[]}>
     */
    public static function validate(array $rows, string $defaultRole): array
    {
        $db = getDB();
        $validRoles = array_column($db->query('SELECT role_key FROM sys_roles')->fetchAll(PDO::FETCH_ASSOC), 'role_key');
        $existingUsers = array_flip(array_map('mb_strtolower', array_column($db->query('SELECT username FROM sys_users')->fetchAll(PDO::FETCH_ASSOC), 'username')));
        $existingExts = array_flip(array_filter(array_column($db->query('SELECT extension FROM sys_users WHERE extension IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC), 'extension')));

        $seenUsers = [];
        $seenExts = [];
        $result = [];
        foreach ($rows as $lineNo => $row) {
            $errors = [];
            if ($row['role'] === '') {
                $row['role'] = $defaultRole;
            }

            if ($row['username'] === '') {
                $errors[] = 'Kullanıcı adı boş';
            } elseif (!preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $row['username'])) {
                $errors[] = 'Kullanıcı adında yalnızca harf, rakam, nokta, tire, alt çizgi ve @ olabilir';
            } else {
                $u = mb_strtolower($row['username']);
                if (isset($existingUsers[$u])) {
                    $errors[] = 'Kullanıcı adı sistemde zaten var';
                } elseif (isset($seenUsers[$u])) {
                    $errors[] = 'Kullanıcı adı dosyada tekrar ediyor (satır ' . $seenUsers[$u] . ')';
                }
                $seenUsers[$u] = $seenUsers[$u] ?? $lineNo;
            }

            if ($row['full_name'] === '') {
                $errors[] = 'Ad soyad boş';
            }
            if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Geçersiz e-posta';
            }
            if ($row['extension'] !== '') {
                if (!preg_match('/^[0-9]{2,10}$/', $row['extension'])) {
                    $errors[] = 'Dahili yalnızca 2-10 haneli rakam olabilir';
                } elseif (isset($existingExts[$row['extension']])) {
                    $errors[] = 'Dahili başka bir kullanıcıya atanmış';
                } elseif (isset($seenExts[$row['extension']])) {
                    $errors[] = 'Dahili dosyada tekrar ediyor (satır ' . $seenExts[$row['extension']] . ')';
                } elseif (($owner = internalNumberOwner($row['extension'])) !== null) {
                    $errors[] = "Numara kullanımda ({$owner})";
                }
                $seenExts[$row['extension']] = $seenExts[$row['extension']] ?? $lineNo;
            }
            if (!in_array($row['role'], $validRoles, true)) {
                $errors[] = "Geçersiz rol: {$row['role']}";
            }
            if ($row['password'] !== '' && mb_strlen($row['password']) < 8) {
                $errors[] = 'Şifre en az 8 karakter olmalı (boş bırakılırsa otomatik oluşturulur)';
            }

            $result[$lineNo] = ['row' => $row, 'errors' => $errors];
        }
        return $result;
    }

    /**
     * Geçerli satırları ekler. Satırlar birbirinden bağımsızdır: biri hata
     * verirse diğerleri eklenmeye devam eder.
     *
     * @return array{created: int, failed: array<int, array{username: string, error: string}>, generated: array<int, array{username: string, full_name: string, extension: string, password: string}>, invited: int}
     */
    public static function import(array $rows, bool $sendInvitations, string $csrfToken): array
    {
        $created = 0;
        $invited = 0;
        $failed = [];
        $generated = [];

        foreach ($rows as $lineNo => $row) {
            $res = UserService::saveUser([
                'csrf_token' => $csrfToken,
                'username' => $row['username'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'extension' => $row['extension'],
                'role' => $row['role'],
                'password' => $row['password'],
                'is_active' => 1,
                'skip_invitation' => $sendInvitations ? 0 : 1,
            ]);
            if (empty($res['success'])) {
                $failed[$lineNo] = ['username' => $row['username'], 'error' => $res['error'] ?? 'Bilinmeyen hata'];
                continue;
            }
            $created++;
            if ($sendInvitations && $row['email'] !== '') {
                $invited++;
            }
            if (!empty($res['generated_password'])) {
                $generated[$lineNo] = [
                    'username' => $row['username'],
                    'full_name' => $row['full_name'],
                    'extension' => $row['extension'],
                    'password' => $res['generated_password'],
                ];
            }
        }

        writeAuditLog(null, 'user_account', 'csv_import', "CSV ile toplu kullanıcı ekleme: {$created} eklendi, " . count($failed) . ' hata', 'create', $_SESSION['user_id'] ?? null);

        return ['created' => $created, 'failed' => $failed, 'generated' => $generated, 'invited' => $invited];
    }
}
