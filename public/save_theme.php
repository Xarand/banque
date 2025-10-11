<?php
declare(strict_types=1);

/**
 * Sauvegarde des couleurs personnalisées dans le cookie "theme_vars" (JSON).
 * Ajout: card_header_bg et card_header_text pour styliser les en-têtes des cartes.
 */

function hexOrNull(?string $v): ?string {
    if (!is_string($v)) return null;
    $v = trim($v);
    if ($v === '') return null;
    return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v) ? strtoupper($v) : null;
}

$reset = isset($_POST['reset']) ? true : false;

if ($reset) {
    @setcookie('theme_vars', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => false,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    header('Location: settings_theme.php?saved=1&reset=1');
    exit;
}

$keys = [
  'primary','app_bg','card_bg','card_header_bg','card_header_text','nav_bg','fg',
  'input_bg','input_fg','input_border','border','thead_bg','muted',
  'table_row_hover','nav_link'
];

$out = [];
foreach ($keys as $k) {
    $val = hexOrNull($_POST[$k] ?? null);
    if ($val !== null) $out[$k] = $val;
}

@setcookie('theme_vars', json_encode($out, JSON_UNESCAPED_SLASHES), [
    'expires'  => time() + 31536000,
    'path'     => '/',
    'secure'   => false,
    'httponly' => false,
    'samesite' => 'Lax',
]);

header('Location: settings_theme.php?saved=1');
exit;