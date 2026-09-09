<?php

class LogoutController extends BaseController
{
    public static function index(): void
    {
        // session_destroy() tek başına sunucu tarafındaki oturum verisini siliyordu
        // ama $_SESSION dizisini boşaltmıyordu ve istemci tarafındaki oturum
        // çerezini açıkça geçersiz kılmıyordu — httponly/secure/samesite bayrakları
        // sayesinde pratik risk düşüktü ama savunma-derinliği için standart çıkış
        // deseni uygulanıyor (2026-08-21 denetiminde bulundu).
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        static::redirect('/login');
    }
}
