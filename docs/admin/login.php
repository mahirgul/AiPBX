<?php
/**
 * AiPBX Admin — Login Page
 */
require_once __DIR__ . '/../includes/session.php';

if (!empty($_SESSION['admin_logged_in']) &&
    (time() - ($_SESSION['admin_login_time'] ?? 0)) < SESSION_LIFETIME) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Yönetici Girişi | AiPBX Admin';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .login-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.5);
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-logo {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            margin-bottom: 16px;
        }
        .login-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #ffffff;
        }
        .login-subtitle {
            font-size: 0.88rem;
            color: #94a3b8;
            margin-top: 6px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-input {
            width: 100%;
            padding: 12px 16px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            color: #ffffff;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.2s;
        }
        .form-input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
        }
        .btn-submit {
            width: 100%;
            padding: 13px;
            background: #0284c7;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }
        .btn-submit:hover {
            background: #0369a1;
        }
        .alert-box {
            display: none;
            padding: 12px;
            border-radius: 8px;
            font-size: 0.86rem;
            margin-bottom: 20px;
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            color: #fca5a5;
        }
        .back-link {
            text-align: center;
            margin-top: 24px;
            font-size: 0.86rem;
        }
        .back-link a {
            color: #38bdf8;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <img src="/logo.png" alt="AiPBX Logo" class="login-logo">
            <h1 class="login-title">AiPBX Yönetim Paneli</h1>
            <p class="login-subtitle">data.json ve sistem parametreleri yönetimi</p>
        </div>

        <div id="alertBox" class="alert-box"></div>

        <form id="loginForm">
            <div class="form-group">
                <label class="form-label" for="username">Kullanıcı Adı</label>
                <input type="text" id="username" class="form-input" required autofocus value="admin">
            </div>
            <div class="form-group">
                <label class="form-label" for="password">Yönetici Parolası</label>
                <input type="password" id="password" class="form-input" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn-submit" id="submitBtn">
                <i class="fa-solid fa-right-to-bracket"></i>
                <span>Giriş Yap</span>
            </button>
        </form>

        <div class="back-link">
            <a href="/"><i class="fa-solid fa-arrow-left"></i> Web Sitesine Dön</a>
        </div>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            const alertBox = document.getElementById('alertBox');
            alertBox.style.display = 'none';

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Giriş yapılıyor...';

            try {
                const res = await fetch('../api/auth.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: document.getElementById('username').value,
                        password: document.getElementById('password').value
                    })
                });
                const data = await res.json();
                if (data.success) {
                    window.location.href = data.redirect || 'index.php';
                } else {
                    alertBox.textContent = data.message || 'Giriş başarısız.';
                    alertBox.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> Giriş Yap';
                }
            } catch (err) {
                alertBox.textContent = 'Sunucuya bağlanılamadı.';
                alertBox.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> Giriş Yap';
            }
        });
    </script>
</body>
</html>
