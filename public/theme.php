<?php
declare(strict_types=1);

/**
 * Feuille de style dynamique (light/dark) + couleurs personnalisées.
 * - Thème via cookie "theme" = light|dark (bascule par toggle_theme.php ou theme.php?theme=dark|light).
 * - Couleurs personnalisées via cookie "theme_vars" (JSON: {primary, app_bg, card_bg, nav_bg, fg, input_bg, input_fg, input_border, border, thead_bg, muted, table_row_hover}).
 */

header('Content-Type: text/css; charset=UTF-8');
header('Vary: Cookie'); // le rendu dépend des cookies
$allowedThemes = ['light','dark'];

// Gestion de ?theme= pour basculer le cookie de thème
if (isset($_GET['theme']) && in_array($_GET['theme'], $allowedThemes, true)) {
    $new = $_GET['theme'];
    @setcookie('theme', $new, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => false,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
} else {
    header('Cache-Control: private, max-age=300'); // 5 min
}

// Thème actuel
$theme = 'light';
if (isset($_COOKIE['theme']) && in_array($_COOKIE['theme'], $allowedThemes, true)) {
    $theme = $_COOKIE['theme'];
}

// Validation d’une couleur HEX (#RGB ou #RRGGBB)
$sanitizeHex = static function (?string $v, ?string $fallback = null): ?string {
    if (!is_string($v)) return $fallback;
    $v = trim($v);
    if ($v === '') return $fallback;
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v)) return strtoupper($v);
    return $fallback;
};

// Palette par défaut selon thème
$primary = '#0D6EFD';
if ($theme === 'dark') {
    $colors = [
        'bg'              => '#0F1115',
        'fg'              => '#E6E6E6',
        'card'            => '#151924',
        'border'          => '#252B3A',
        'nav_bg'          => '#1A2030',
        'nav_link'        => '#CBD3E1',
        'nav_link_active' => $primary,
        'thead_bg'        => '#111522',
        'muted'           => '#9AA7BD',
        'input_bg'        => '#0F1320',
        'input_fg'        => '#E6E6E6',
        'input_border'    => '#2A3348',
        'table_row_hover' => '#1A2130',
    ];
} else {
    $colors = [
        'bg'              => '#F5F6F8',
        'fg'              => '#212529',
        'card'            => '#FFFFFF',
        'border'          => '#DEE2E6',
        'nav_bg'          => '#F8F9FA',
        'nav_link'        => '#495057',
        'nav_link_active' => $primary,
        'thead_bg'        => '#F1F3F5',
        'muted'           => '#6C757D',
        'input_bg'        => '#FFFFFF',
        'input_fg'        => '#212529',
        'input_border'    => '#CED4DA',
        'table_row_hover' => '#F8F9FA',
    ];
}

// Surcharges utilisateur depuis le cookie theme_vars (JSON)
$raw = $_COOKIE['theme_vars'] ?? '';
if (is_string($raw) && $raw !== '') {
    $vars = json_decode($raw, true);
    if (is_array($vars)) {
        // Accent principal
        $primary = $sanitizeHex($vars['primary'] ?? null, $primary);

        // Contexte
        $colors['bg']           = $sanitizeHex($vars['app_bg']        ?? null, $colors['bg']);
        $colors['fg']           = $sanitizeHex($vars['fg']            ?? null, $colors['fg']);
        $colors['card']         = $sanitizeHex($vars['card_bg']       ?? null, $colors['card']);
        $colors['border']       = $sanitizeHex($vars['border']        ?? null, $colors['border']);
        $colors['nav_bg']       = $sanitizeHex($vars['nav_bg']        ?? null, $colors['nav_bg']);
        $colors['thead_bg']     = $sanitizeHex($vars['thead_bg']      ?? null, $colors['thead_bg']);
        $colors['muted']        = $sanitizeHex($vars['muted']         ?? null, $colors['muted']);
        $colors['input_bg']     = $sanitizeHex($vars['input_bg']      ?? null, $colors['input_bg']);
        $colors['input_fg']     = $sanitizeHex($vars['input_fg']      ?? null, $colors['input_fg']);
        $colors['input_border'] = $sanitizeHex($vars['input_border']  ?? null, $colors['input_border']);
        $colors['table_row_hover'] = $sanitizeHex($vars['table_row_hover'] ?? null, $colors['table_row_hover']);

        // Les liens actifs de nav suivent la couleur primaire
        $colors['nav_link_active'] = $primary;
        // Optionnel: nav_link
        if (!empty($vars['nav_link'])) {
            $colors['nav_link'] = $sanitizeHex($vars['nav_link'], $colors['nav_link']);
        }
    }
}
?>
:root {
  --primary: <?= $primary ?>;
  --app-bg: <?= $colors['bg'] ?>;
  --app-fg: <?= $colors['fg'] ?>;
  --card-bg: <?= $colors['card'] ?>;
  --app-border: <?= $colors['border'] ?>;
  --nav-bg: <?= $colors['nav_bg'] ?>;
  --nav-link: <?= $colors['nav_link'] ?>;
  --nav-link-active: <?= $colors['nav_link_active'] ?>;
  --thead-bg: <?= $colors['thead_bg'] ?>;
  --text-muted: <?= $colors['muted'] ?>;
  --input-bg: <?= $colors['input_bg'] ?>;
  --input-fg: <?= $colors['input_fg'] ?>;
  --input-border: <?= $colors['input_border'] ?>;
  --table-row-hover: <?= $colors['table_row_hover'] ?>;
}

/* Corps et cartes */
body { background: var(--app-bg); color: var(--app-fg); }
.card { background: var(--card-bg); border-color: var(--app-border); }
.border, .table, .form-control, .form-select, .btn-outline-secondary { border-color: var(--app-border) !important; }

/* Navbar */
.navbar { background-color: var(--nav-bg) !important; border-bottom: 1px solid var(--app-border); }
.navbar .navbar-brand { color: var(--nav-link); }
.navbar .navbar-brand:hover { color: var(--nav-link-active); }
.navbar .nav-link { color: var(--nav-link); }
.navbar .nav-link:hover { color: var(--nav-link-active); }
.navbar .nav-link.active, .navbar .nav-link.show { color: var(--nav-link-active); font-weight: 600; }

/* Toggler (burger) */
.navbar .navbar-toggler { border-color: rgba(127,127,127,.35); }
<?php if ($theme === 'dark'): ?>
.navbar .navbar-toggler-icon { filter: invert(1) brightness(1.2); }
<?php else: ?>
.navbar .navbar-toggler-icon { filter: none; }
<?php endif; ?>

/* Tableaux */
.table { color: inherit; }
.table thead { background: var(--thead-bg); color: inherit; }
.table-hover tbody tr:hover { background-color: var(--table-row-hover); }

/* Formulaires */
.form-control, .form-select, .form-check-input { background: var(--input-bg); color: var(--input-fg); }
.form-control, .form-select { border-color: var(--input-border); }
.form-control:focus, .form-select:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 .2rem rgba(13,110,253,.25);
}

/* Boutons de base */
.btn-primary { background-color: var(--primary); border-color: var(--primary); }
.btn-primary:hover { filter: brightness(0.95); }
.btn-outline-secondary { color: var(--nav-link); }
.btn-outline-secondary:hover { color: var(--nav-link-active); border-color: var(--nav-link-active); }

/* Badges et progrès */
.badge-micro { background: var(--primary); }
.progress { background-color: rgba(127,127,127,.15); }

/* Textes utiles */
.text-muted, .form-text { color: var(--text-muted) !important; }

/* Liens */
a { color: var(--primary); }
a:hover { color: var(--nav-link-active); }

/* Bordures de cells */
.table > :not(caption) > * > * { border-color: var(--app-border); }

/* Helpers */
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Courier New", monospace; }

/* Modales en sombre */
<?php if ($theme === 'dark'): ?>
.modal-content { background: var(--card-bg); color: var(--app-fg); border-color: var(--app-border); }
<?php endif; ?>