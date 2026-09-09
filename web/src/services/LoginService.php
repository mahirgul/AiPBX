<?php
/**
 * Login (Giriş) Service
 */
class LoginService {
    /**
     * @return array{redirect?:string, error?:string}
     */
    public static function attemptLogin(array $post, string $clientIp): array
    {
        $username = trim($post['username'] ?? '');
        $password = trim($post['password'] ?? '');
        $user_captcha = intval($post['captcha_answer'] ?? 0);
        $csrf_token = $post['csrf_token'] ?? '';

        $correct_captcha = ($_SESSION['captcha_num1'] ?? 0) + ($_SESSION['captcha_num2'] ?? 0);

        // Check 1: CSRF Token
        if (!verifyCSRFToken($csrf_token)) {
            return ['error' => 'Güvenlik doğrulaması (CSRF) başarısız! Lütfen sayfayı yenileyip tekrar deneyin.'];
        }
        // Check 2: Brute force lockout check (5 failures in 15 mins)
        if (checkBruteForceLockout($clientIp, $username)) {
            return ['error' => 'Çok fazla hatalı deneme yapıldı! Hesabınız ve IP adresiniz 15 dakika süreyle kilitlenmiştir.'];
        }
        // Check 3: Math Captcha
        if ($user_captcha !== $correct_captcha) {
            logLoginAttempt($clientIp, $username, 'FAILED');
            return ['error' => 'Güvenlik kodu (matematik sorusu) hatalı!'];
        }
        // Check 4: User authentication
        if (empty($username) || empty($password)) {
            return ['error' => 'Lütfen tüm alanları doldurun!'];
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, password_hash, full_name, role, extension, theme_preference, language_preference, is_active, must_reset_password FROM sys_users WHERE username = ? OR extension = ?');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        $is_authenticated = false;
        if ($user && $user['is_active'] == 1 && password_verify($password, $user['password_hash'])) {
            $is_authenticated = true;
        }

        if ($is_authenticated && !empty($user['must_reset_password'])) {
            // Şifresi zorunlu sıfırlamaya işaretli hesap: normal oturum açılmaz,
            // yalnızca sıfırlama akışına girebilecek geçici bir işaret bırakılır.
            session_regenerate_id(true);
            $_SESSION['pending_reset_user_id'] = $user['id'];
            logLoginAttempt($clientIp, $username, 'SUCCESS');
            unset($_SESSION['captcha_num1'], $_SESSION['captcha_num2']);
            return ['redirect' => '/force-reset'];
        }

        if ($is_authenticated) {
            // Regenerate Session ID to prevent Session Fixation Attacks
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['extension'] = $user['extension'];
            $_SESSION['theme'] = $user['theme_preference'] ?? 'light';
            $_SESSION['ui_language'] = isset(UI_LANGUAGES[$user['language_preference'] ?? '']) ? $user['language_preference'] : 'tr';

            // Log Successful Login
            logLoginAttempt($clientIp, $username, 'SUCCESS');

            // Reset captcha
            unset($_SESSION['captcha_num1'], $_SESSION['captcha_num2']);

            // Role-based redirect
            if ($user['role'] === 'admin') {
                $redirect = '/dashboard';
            } elseif ($user['role'] === 'cc_agent') {
                $redirect = '/cc-agent';
            } else {
                $redirect = '/fax-inbox';
            }
            return ['redirect' => $redirect];
        }

        logLoginAttempt($clientIp, $username, 'FAILED');
        return ['error' => 'Geçersiz kullanıcı adı, dahili numara veya şifre!'];
    }
}
