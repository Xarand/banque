<?php
declare(strict_types=1);

namespace App;

class Util
{
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function regenerateSessionId(): void
    {
        session_regenerate_id(true);
    }

    public static function currentUserId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    // Ajout (nouvelle méthode)
    public static function isAuthenticated(): bool
    {
        return self::currentUserId() !== null;
    }

    public static function requireAuth(): void
    {
        self::startSession();
        if (!self::currentUserId()) {
            self::redirect('login.php?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
        }
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    public static function h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /* CSRF basique */
    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['_csrf'];
    }

    public static function csrfInput(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::h(self::csrfToken()) . '">';
    }

    public static function checkCsrf(): void
    {
        self::startSession();
        $expected = $_SESSION['_csrf'] ?? null;
        $given = $_POST['_csrf'] ?? '';
        if (!$expected || !hash_equals($expected, $given)) {
            throw new \RuntimeException('CSRF token invalide.');
        }
    }

    /* Flash */
    public static function addFlash(string $type, string $msg): void
    {
        self::startSession();
        $_SESSION['_flashes'][] = ['type'=>$type, 'msg'=>$msg];
    }

    public static function takeFlashes(): array
    {
        self::startSession();
        $f = $_SESSION['_flashes'] ?? [];
        unset($_SESSION['_flashes']);
        return $f;
    }
}