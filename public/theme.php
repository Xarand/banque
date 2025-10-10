<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
use App\{Util, Database};

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

// Helpers schéma
function ensureColumn(PDO $pdo, string $table, string $col): void {
    $st = $pdo->prepare("PRAGMA table_info($table)");
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (strcasecmp((string)$c['name'], $col) === 0) return;
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $col TEXT");
}

// Table paramètres
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_settings (
  user_id INTEGER PRIMARY KEY,
  navbar_bg TEXT, navbar_text TEXT,
  body_bg TEXT, body_text TEXT,
  table_header_bg TEXT, table_header_text TEXT,
  link_color TEXT
);
");

// Colonnes additionnelles (UI complète) + mode
foreach ([
  'card_bg','card_text','card_header_bg','card_header_text','card_border',
  'table_row_bg','table_row_alt_bg','table_row_hover_bg',
  'input_bg','input_text','input_border','input_focus',
  'btn_primary_bg','btn_primary_text',
  'theme_mode'
] as $c) ensureColumn($pdo, 'user_settings', $c);

// Charger préférences
$st = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = :u");
$st->execute([':u'=>$userId]);
$row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

// Mode (session prioritaire, sinon base, sinon light)
$mode = $_SESSION['theme_mode'] ?? ($row['theme_mode'] ?? 'light');

// Palettes par défaut
$light = [
  'navbar_bg'          => '#212529',
  'navbar_text'        => '#ffffff',
  'body_bg'            => '#f5f6f8',
  'body_text'          => '#212529',
  'table_header_bg'    => '#f8f9fa',
  'table_header_text'  => '#212529',
  'link_color'         => '#0d6efd',
  'card_bg'            => '#ffffff',
  'card_text'          => '#212529',
  'card_header_bg'     => '#f8f9fa',
  'card_header_text'   => '#212529',
  'card_border'        => '#dee2e6',
  'table_row_bg'       => '#ffffff',
  'table_row_alt_bg'   => '#f9fbfd',
  'table_row_hover_bg' => '#eef3f8',
  'input_bg'           => '#ffffff',
  'input_text'         => '#212529',
  'input_border'       => '#ced4da',
  'input_focus'        => '#86b7fe',
  'btn_primary_bg'     => '#0d6efd',
  'btn_primary_text'   => '#ffffff',
];

$dark = [
  'navbar_bg'          => '#111827', // slate-800
  'navbar_text'        => '#e5e7eb', // gray-200
  'body_bg'            => '#0f1115',
  'body_text'          => '#e6e6e6',
  'table_header_bg'    => '#1f2937', // slate-700
  'table_header_text'  => '#e5e7eb',
  'link_color'         => '#60a5fa', // blue-400
  'card_bg'            => '#111827', // slate-800
  'card_text'          => '#e5e7eb',
  'card_header_bg'     => '#0b1220',
  'card_header_text'   => '#e5e7eb',
  'card_border'        => '#2b3441',
  'table_row_bg'       => '#121722',
  'table_row_alt_bg'   => '#0f1420',
  'table_row_hover_bg' => '#1a2130',
  'input_bg'           => '#0f1420',
  'input_text'         => '#e5e7eb',
  'input_border'       => '#2b3441',
  'input_focus'        => '#3b82f6',
  'btn_primary_bg'     => '#3b82f6',
  'btn_primary_text'   => '#ffffff',
];

// Règle d’application:
// - Light => on part des defaults light et on fusionne les valeurs sauvegardées (personnalisation).
// - Dark  => on applique la palette dark (cohérente immédiatement). Si tu veux des réglages dark personnalisés,
//           dis-le et on ajoutera des champs dédiés (_dark) pour les surcharger.
$base = ($mode === 'dark') ? $dark : $light;
$cfg = $base;
if ($mode !== 'dark' && $row) {
    foreach ($row as $k => $v) {
        if ($v !== null && $v !== '' && array_key_exists($k, $cfg)) {
            $cfg[$k] = $v;
        }
    }
}

// Réponse CSS
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
:root {
  --app-navbar-bg:           <?= $cfg['navbar_bg'] ?>;
  --app-navbar-text:         <?= $cfg['navbar_text'] ?>;
  --app-body-bg:             <?= $cfg['body_bg'] ?>;
  --app-body-text:           <?= $cfg['body_text'] ?>;
  --app-table-header-bg:     <?= $cfg['table_header_bg'] ?>;
  --app-table-header-text:   <?= $cfg['table_header_text'] ?>;
  --app-link:                <?= $cfg['link_color'] ?>;

  --app-card-bg:             <?= $cfg['card_bg'] ?>;
  --app-card-text:           <?= $cfg['card_text'] ?>;
  --app-card-header-bg:      <?= $cfg['card_header_bg'] ?>;
  --app-card-header-text:    <?= $cfg['card_header_text'] ?>;
  --app-card-border:         <?= $cfg['card_border'] ?>;

  --app-table-row-bg:        <?= $cfg['table_row_bg'] ?>;
  --app-table-row-alt-bg:    <?= $cfg['table_row_alt_bg'] ?>;
  --app-table-row-hover-bg:  <?= $cfg['table_row_hover_bg'] ?>;

  --app-input-bg:            <?= $cfg['input_bg'] ?>;
  --app-input-text:          <?= $cfg['input_text'] ?>;
  --app-input-border:        <?= $cfg['input_border'] ?>;
  --app-input-focus:         <?= $cfg['input_focus'] ?>;

  --app-primary-bg:          <?= $cfg['btn_primary_bg'] ?>;
  --app-primary-text:        <?= $cfg['btn_primary_text'] ?>;

  /* Mapping Bootstrap */
  --bs-body-bg:              var(--app-body-bg);
  --bs-body-color:           var(--app-body-text);
  --bs-card-bg:              var(--app-card-bg);
  --bs-card-color:           var(--app-card-text);
  --bs-card-border-color:    var(--app-card-border);
  --bs-card-cap-bg:          var(--app-card-header-bg);
  --bs-card-cap-color:       var(--app-card-header-text);
  --bs-link-color:           var(--app-link);
  --bs-link-hover-color:     var(--app-link);
  --bs-primary:              var(--app-primary-bg);
}

body { background: var(--app-body-bg); color: var(--app-body-text); }

/* Bandeau */
.navbar { background-color: var(--app-navbar-bg) !important; }
.navbar .navbar-brand,
.navbar .nav-link,
.navbar .btn,
.navbar .btn-outline-light {
  color: var(--app-navbar-text) !important;
  border-color: var(--app-navbar-text) !important;
}
.navbar .nav-link.active { text-decoration: underline; }

/* Liens */
a { color: var(--app-link); }
a:hover { filter: brightness(0.9); }

/* Cartes */
.card {
  background-color: var(--app-card-bg) !important;
  color: var(--app-card-text) !important;
  border-color: var(--app-card-border) !important;
}
.card > .card-header,
.card .card-header {
  background-color: var(--app-card-header-bg) !important;
  color: var(--app-card-header-text) !important;
  border-bottom-color: var(--app-card-border) !important;
}
.card > .card-body,
.card .card-body,
.card > .card-footer,
.card .card-footer {
  background-color: var(--app-card-bg) !important;
  color: var(--app-card-text) !important;
}
.card .bg-light { background-color: var(--app-card-header-bg) !important; color: var(--app-card-header-text) !important; }
.card .bg-white { background-color: var(--app-card-bg) !important; color: var(--app-card-text) !important; }

/* Tableaux */
.table thead,
.table .table-light,
.table thead.table-light {
  background-color: var(--app-table-header-bg) !important;
  color: var(--app-table-header-text) !important;
}
.table tbody tr { background-color: var(--app-table-row-bg); }
.table.table-striped tbody tr:nth-of-type(odd) { background-color: var(--app-table-row-alt-bg); }
.table tbody tr:hover { background-color: var(--app-table-row-hover-bg); }

/* Formulaires */
.form-control, .form-select, .form-check-input {
  background-color: var(--app-input-bg) !important;
  color: var(--app-input-text) !important;
  border-color: var(--app-input-border) !important;
}
.form-control:focus, .form-select:focus {
  border-color: var(--app-input-focus) !important;
  box-shadow: 0 0 0 .2rem rgba(13,110,253,.25);
}

/* Bouton principal */
.btn-primary {
  background-color: var(--app-primary-bg) !important;
  border-color: var(--app-primary-bg) !important;
  color: var(--app-primary-text) !important;
}
.btn-primary:hover { filter: brightness(0.95); }