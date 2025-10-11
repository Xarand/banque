<?php
declare(strict_types=1);

/**
 * Feuille de style dynamique (THÈME CLAIR UNIQUEMENT) + couleurs personnalisées.
 * - Mode sombre supprimé.
 * - Couleurs personnalisées via cookie "theme_vars" (JSON).
 */

header('Content-Type: text/css; charset=UTF-8');
header('Vary: Cookie');                      // le rendu varie avec theme_vars
header('Cache-Control: private, max-age=300'); // cache léger

// Validation d’une couleur HEX (#RGB ou #RRGGBB)
$hex = static function (?string $v, ?string $fallback = null): ?string {
    if (!is_string($v)) return $fallback;
    $v = trim($v);
    if ($v === '') return $fallback;
    return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v) ? strtoupper($v) : $fallback;
};

// Palette par défaut (clair)
$primary = '#0D6EFD';
$colors = [
    'bg'               => '#F5F6F8',
    'fg'               => '#212529',
    'card'             => '#FFFFFF',
    'card_header_bg'   => '#F8F9FA',
    'card_header_text' => '#212529',
    'border'           => '#DEE2E6',
    'nav_bg'           => '#F8F9FA',
    'nav_link'         => '#495057',
    'nav_link_active'  => $primary,
    'thead_bg'         => '#F1F3F5',
    'muted'            => '#6C757D',
    'input_bg'         => '#FFFFFF',
    'input_fg'         => '#212529',
    'input_border'     => '#CED4DA',
    'table_row_hover'  => '#F8F9FA',
];

// Surcharges via cookie "theme_vars" (JSON)
$raw = $_COOKIE['theme_vars'] ?? '';
if (is_string($raw) && $raw !== '') {
    $vars = json_decode($raw, true);
    if (is_array($vars)) {
        $primary                    = $hex($vars['primary']         ?? null, $primary) ?: $primary;
        $colors['bg']               = $hex($vars['app_bg']          ?? null, $colors['bg'])               ?: $colors['bg'];
        $colors['fg']               = $hex($vars['fg']              ?? null, $colors['fg'])               ?: $colors['fg'];
        $colors['card']             = $hex($vars['card_bg']         ?? null, $colors['card'])             ?: $colors['card'];
        $colors['card_header_bg']   = $hex($vars['card_header_bg']  ?? null, $colors['card_header_bg'])   ?: $colors['card_header_bg'];
        $colors['card_header_text'] = $hex($vars['card_header_text']?? null, $colors['card_header_text']) ?: $colors['card_header_text'];
        $colors['border']           = $hex($vars['border']          ?? null, $colors['border'])           ?: $colors['border'];
        $colors['nav_bg']           = $hex($vars['nav_bg']          ?? null, $colors['nav_bg'])           ?: $colors['nav_bg'];
        $colors['thead_bg']         = $hex($vars['thead_bg']        ?? null, $colors['thead_bg'])         ?: $colors['thead_bg'];
        $colors['muted']            = $hex($vars['muted']           ?? null, $colors['muted'])            ?: $colors['muted'];
        $colors['input_bg']         = $hex($vars['input_bg']        ?? null, $colors['input_bg'])         ?: $colors['input_bg'];
        $colors['input_fg']         = $hex($vars['input_fg']        ?? null, $colors['input_fg'])         ?: $colors['input_fg'];
        $colors['input_border']     = $hex($vars['input_border']    ?? null, $colors['input_border'])     ?: $colors['input_border'];
        $colors['table_row_hover']  = $hex($vars['table_row_hover'] ?? null, $colors['table_row_hover'])  ?: $colors['table_row_hover'];
        if (!empty($vars['nav_link'])) {
            $colors['nav_link'] = $hex($vars['nav_link'], $colors['nav_link']) ?: $colors['nav_link'];
        }
        $colors['nav_link_active'] = $primary;
    }
}
?>
:root {
  --primary: <?= $primary ?>;
  --app-bg: <?= $colors['bg'] ?>;
  --app-fg: <?= $colors['fg'] ?>;
  --card-bg: <?= $colors['card'] ?>;
  --card-header-bg: <?= $colors['card_header_bg'] ?>;
  --card-header-text: <?= $colors['card_header_text'] ?>;
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
.card { background: var(--card-bg); color: var(--app-fg); border-color: var(--app-border); }
.card-header { background: var(--card-header-bg); color: var(--card-header-text); border-bottom-color: var(--app-border); }
.border, .table, .form-control, .form-select, .btn-outline-secondary { border-color: var(--app-border) !important; }

/* Navbar */
.navbar { background-color: var(--nav-bg) !important; border-bottom: 1px solid var(--app-border); }
.navbar .navbar-brand { color: var(--nav-link); }
.navbar .navbar-brand:hover { color: var(--nav-link-active); }
.navbar .nav-link { color: var(--nav-link); }
.navbar .nav-link:hover, .navbar .nav-link.active, .navbar .nav-link.show { color: var(--nav-link-active); }
.navbar .navbar-toggler { border-color: rgba(127,127,127,.35); }

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

/* Boutons */
.btn-primary { background-color: var(--primary); border-color: var(--primary); }
.btn-primary:hover { filter: brightness(0.95); }
.btn-outline-secondary { color: var(--nav-link); }
.btn-outline-secondary:hover { color: var(--nav-link-active); border-color: var(--nav-link-active); }

/* Badges/Progress */
.badge-micro { background: var(--primary); }
.progress { background-color: rgba(127,127,127,.15); }

/* Textes utiles */
.text-muted, .form-text { color: var(--text-muted) !important; }

/* Liens */
a { color: var(--primary); }
a:hover { color: var(--nav-link-active); }

/* Bordures table */
.table > :not(caption) > * > * { border-color: var(--app-border); }

/* Helpers */
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Courier New", monospace; }