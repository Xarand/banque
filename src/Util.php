<?php
declare(strict_types=1);

namespace App;

final class Util
{
    private const CSRF_KEY  = '_csrf_token';
    private const FLASH_KEY = '_flashes';

    // Session
    public static function startSession(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (!isset($_SESSION[self::FLASH_KEY]) || !is_array($_SESSION[self::FLASH_KEY])) {
            $_SESSION[self::FLASH_KEY] = [];
        }
    }

    // Utilisateur courant
    public static function currentUserId(): int
    {
        self::startSession();
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    }

    // Etat d'auth
    public static function isAuthenticated(): bool
    {
        return self::currentUserId() > 0;
    }

    // Alias legacy
    public static function isLogged(): bool
    {
        return self::isAuthenticated();
    }

    // Exiger connexion
    public static function requireAuth(string $redirectTo = 'login.php'): void
    {
        if (!self::isAuthenticated()) {
            self::redirect($redirectTo);
        }
    }

    // Connexion: définit l'utilisateur en session (compat Util::loginUser attendu par login.php)
    public static function loginUser(int $userId): void
    {
        self::startSession();
        // Régénère l'ID de session pour éviter la fixation de session
        @session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    // Alias legacy éventuels
    public static function login(int $userId): void
    {
        self::loginUser($userId);
    }

    // Déconnexion
    public static function logoutUser(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        @session_destroy();
    }

    // Alias legacy
    public static function logout(): void
    {
        self::logoutUser();
    }

    // CSRF
    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION[self::CSRF_KEY];
    }

    public static function csrfInput(): string
    {
        $t = self::csrfToken();
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    public static function checkCsrf(): void
    {
        self::startSession();
        $ok = isset($_POST['_csrf'], $_SESSION[self::CSRF_KEY])
           && hash_equals((string)$_SESSION[self::CSRF_KEY], (string)$_POST['_csrf']);
        if (!$ok) {
            http_response_code(400);
            throw new \RuntimeException('CSRF invalide.');
        }
    }

    // Flashes
    public static function addFlash(string $type, string $msg): void
    {
        self::startSession();
        $_SESSION[self::FLASH_KEY][] = ['type' => $type, 'msg' => $msg];
    }

    public static function takeFlashes(): array
    {
        self::startSession();
        $all = $_SESSION[self::FLASH_KEY] ?? [];
        $_SESSION[self::FLASH_KEY] = [];
        return $all;
    }

    // Redirection
    public static function redirect(string $url): void
    {
        if ($url === '') { $url = 'index.php'; }
        header('Location: ' . $url);
        exit;
    }

    // Echappement
    public static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}