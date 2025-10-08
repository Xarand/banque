<?php
declare(strict_types=1);

namespace App;

final class Util
{
    private const SESSION_USER_KEY  = 'user_id';
    private const SESSION_CSRF_KEY  = '_csrf';
    private const SESSION_FLASH_KEY = '_flashes';
    private const CSRF_BYTES        = 32;

    private function __construct() {}

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $cookieParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime'=>0,
            'path'=>$cookieParams['path'] ?? '/',
            'domain'=>$cookieParams['domain'] ?? '',
            'secure'=>false,
            'httponly'=>true,
            'samesite'=>'Strict',
        ]);
        session_start();
        if (empty($_SESSION['_session_init'])) {
            $_SESSION['_session_init'] = time();
            session_regenerate_id(true);
        }
    }

    public static function loginUser(int $userId, bool $regen=false): void
    {
        if ($regen) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_USER_KEY] = $userId;
    }

    public static function logoutUser(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000,
                $p['path'] ?? '/', $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true
            );
        }
        session_destroy();
    }

    public static function isLogged(): bool
    {
        return !empty($_SESSION[self::SESSION_USER_KEY]);
    }

    public static function currentUserId(): int
    {
        return (int)($_SESSION[self::SESSION_USER_KEY] ?? 0);
    }

    public static function requireAuth(): void
    {
        if (!self::isLogged()) {
            self::addFlash('warning','Veuillez vous connecter.');
            self::redirect('login.php');
        }
    }

    public static function requireAdmin(\PDO $pdo): void
    {
        self::requireAuth();
        $uid = self::currentUserId();
        $st = $pdo->prepare("SELECT is_admin FROM users WHERE id=:id LIMIT 1");
        $st->execute([':id'=>$uid]);
        $is = (int)$st->fetchColumn();
        if ($is !== 1) {
            self::addFlash('danger','Accès refusé (admin requis).');
            self::redirect('index.php');
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION[self::SESSION_CSRF_KEY])) {
            $_SESSION[self::SESSION_CSRF_KEY] = bin2hex(random_bytes(self::CSRF_BYTES));
        }
        return $_SESSION[self::SESSION_CSRF_KEY];
    }

    public static function csrfInput(): string
    {
        return '<input type="hidden" name="_csrf" value="'.self::h(self::csrfToken()).'">';
    }

    public static function checkCsrf(): void
    {
        $expected = $_SESSION[self::SESSION_CSRF_KEY] ?? '';
        $given = $_POST['_csrf'] ?? $_GET['_csrf'] ?? '';
        if (!is_string($given) || $given === '' || !hash_equals($expected,$given)) {
            unset($_SESSION[self::SESSION_CSRF_KEY]);
            throw new \RuntimeException('CSRF token invalide ou manquant.');
        }
    }

    public static function addFlash(string $type,string $msg): void
    {
        $_SESSION[self::SESSION_FLASH_KEY][] = ['type'=>$type,'msg'=>$msg];
    }

    public static function takeFlashes(): array
    {
        $fl = $_SESSION[self::SESSION_FLASH_KEY] ?? [];
        unset($_SESSION[self::SESSION_FLASH_KEY]);
        return $fl;
    }

    public static function redirect(string $path): void
    {
        header('Location: '.$path);
        exit;
    }

    public static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function floatOrNull(mixed $v): ?float
    {
        if ($v === null) return null;
        if (is_float($v)) return $v;
        if (is_int($v)) return (float)$v;
        if (!is_string($v)) return null;
        $s = trim(str_replace(["\u{00A0}", ' '], '', $v));
        if ($s==='') return null;
        $s = str_replace(',', '.', $s);
        if (!preg_match('/^-?\d+(\.\d+)?$/',$s)) return null;
        return (float)$s;
    }

    public static function toBool(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v===1;
        if (is_string($v)) {
            $sv = strtolower(trim($v));
            return in_array($sv,['1','true','yes','on'], true);
        }
        return false;
    }

    public static function randomString(int $bytes=16): string
    {
        return bin2hex(random_bytes(max(1,$bytes)));
    }
}