<?php
/**
 * AiPBX Admin — Setup Password
 */
require_once __DIR__ . '/../config.php';

$credsFile = __DIR__ . '/.credentials.php';

if (ADMIN_SETUP_DONE && file_exists($credsFile)) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password_confirm'] ?? '');

    if (strlen($p1) < 4) {
        $error = 'Parola en az 4 karakter olmalıdır.';
    } elseif ($p1 !== $p2) {
        $error = 'Parolalar eşleşmiyor.';
    } else {
        $hash = password_hash($p1, PASSWORD_BCRYPT);
        $content = "<?php\nreturn [\"hash\" => '" . addslashes($hash) . "'];\n";
        if (file_put_contents($credsFile, $content, LOCK_EX)) {
            $success = 'Parola oluşturuldu! Giriş sayfasına yönlendiriliyorsunuz...';
            header("refresh:2;url=login.php");
        } else {
            $error = 'Dosya yazılamadı. Klasör izinlerini kontrol edin.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Yönetici Kurulumu | AiPBX</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin:0; padding:0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0f172a; color:#f8fafc; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding:36px; width:100%; max-width:400px; }
        .title { font-size:1.4rem; font-weight:700; margin-bottom:8px; }
        .input { width:100%; padding:10px 14px; background:#0f172a; border:1px solid #334155; border-radius:8px; color:#fff; margin-top:6px; margin-bottom:16px; outline:none; }
        .btn { width:100%; padding:12px; background:#0284c7; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; }
        .alert { padding:10px; border-radius:8px; font-size:0.88rem; margin-bottom:16px; }
        .alert-error { background:rgba(239,68,68,0.15); border:1px solid #ef4444; color:#fca5a5; }
        .alert-success { background:rgba(16,185,129,0.15); border:1px solid #10b981; color:#6ee7b7; }
    </style>
</head>
<body>
    <div class="card">
        <h1 class="title">Yönetici Kurulumu</h1>
        <p style="font-size:0.88rem; color:#94a3b8; margin-bottom:20px;">AiPBX Yönetim Paneli için yönetici parolası belirleyin.</p>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <form method="POST">
            <label style="font-size:0.82rem; font-weight:600;">Yeni Parola</label>
            <input type="password" name="password" class="input" required minlength="4">
            <label style="font-size:0.82rem; font-weight:600;">Parola (Tekrar)</label>
            <input type="password" name="password_confirm" class="input" required minlength="4">
            <button type="submit" class="btn">Parolayı Kaydet</button>
        </form>
    </div>
</body>
</html>
