<?php
declare(strict_types=1);

/**
 * Bascule le cookie "theme" entre light et dark puis redirige vers la page d'origine.
 * Utilisation: GET /toggle_theme.php (ou avec ?to=dark|light)
 */
$allowed = ['light','dark'];
$to = $_GET['to'] ?? null;

if ($to === null) {
    $cur = isset($_COOKIE['theme']) && in_array($_COOKIE['theme'], $allowed, true) ? $_COOKIE['theme'] : 'light';
    $to  = ($cur === 'dark') ? 'light' : 'dark';
} elseif (!in_array($to, $allowed, true)) {
    $to = 'light';
}

@setcookie('theme', $to, [
    'expires'  => time() + 31536000,
    'path'     => '/',
    'secure'   => false,
    'httponly' => false,
    'samesite' => 'Lax',
]);

// Redirection vers le referer (ou index)
$back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header('Location: ' . $back);
exit;