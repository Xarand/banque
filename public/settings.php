<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
use App\{Util, Database};

ini_set('display_errors','1');
error_reporting(E_ALL);

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

function h(string $s): string { return App\Util::h($s); }
function hasTable(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
    $st->execute([':t'=>$table]);
    return (bool)$st->fetchColumn();
}
function hasCol(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("PRAGMA table_info($table)");
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (strcasecmp((string)$c['name'], $col) === 0) return true;
    }
    return false;
}
function ensureColumn(PDO $pdo, string $table, string $col, string $typeSql = "TEXT"): void {
    if (!hasCol($pdo, $table, $col)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $col $typeSql");
    }
}
function validColor(?string $v, string $fallback): string {
    $v = trim((string)$v);
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? $v : $fallback;
}

/* ---------------- Thème (couleurs UI) ---------------- */

$pdo->exec("
CREATE TABLE IF NOT EXISTS user_settings (
  user_id INTEGER PRIMARY KEY,
  navbar_bg TEXT,
  navbar_text TEXT,
  body_bg TEXT,
  body_text TEXT,
  table_header_bg TEXT,
  table_header_text TEXT,
  link_color TEXT
);
");
foreach ([
  'card_bg','card_text','card_header_bg','card_header_text','card_border',
  'table_row_bg','table_row_alt_bg','table_row_hover_bg',
  'input_bg','input_text','input_border','input_focus',
  'btn_primary_bg','btn_primary_text',
  'theme_mode'
] as $c) ensureColumn($pdo, 'user_settings', $c);

$defaults = [
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

$stCfg = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = :u");
$stCfg->execute([':u'=>$userId]);
$theme = $stCfg->fetch(PDO::FETCH_ASSOC) ?: [];

/* ---------------- Catégories ---------------- */

$hasCategories = hasTable($pdo, 'categories');
$trxHasUser    = hasCol($pdo, 'transactions', 'user_id');
$trxHasCat     = hasCol($pdo, 'transactions', 'category_id');
$catHasUser    = $hasCategories ? hasCol($pdo, 'categories', 'user_id') : false;

// S'assure que la colonne type (credit|debit) existe si la table existe
if ($hasCategories && !hasCol($pdo, 'categories', 'type')) {
    // Ajoute colonne type avec contrainte douce (SQLite ne renforce pas CHECK sur ajout)
    ensureColumn($pdo, 'categories', 'type', "TEXT");
    // Optionnel: initialiser à 'debit' par défaut
    $pdo->exec("UPDATE categories SET type='debit' WHERE type IS NULL OR type=''");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();
        $action = (string)($_POST['action'] ?? '');

        /* ---- Thème ---- */
        if ($action === 'save_theme') {
            $in = $_POST;
            $data = [
              'navbar_bg'          => validColor($in['navbar_bg'] ?? null, $defaults['navbar_bg']),
              'navbar_text'        => validColor($in['navbar_text'] ?? null, $defaults['navbar_text']),
              'body_bg'            => validColor($in['body_bg'] ?? null, $defaults['body_bg']),
              'body_text'          => validColor($in['body_text'] ?? null, $defaults['body_text']),
              'table_header_bg'    => validColor($in['table_header_bg'] ?? null, $defaults['table_header_bg']),
              'table_header_text'  => validColor($in['table_header_text'] ?? null, $defaults['table_header_text']),
              'link_color'         => validColor($in['link_color'] ?? null, $defaults['link_color']),
              'card_bg'            => validColor($in['card_bg'] ?? null, $defaults['card_bg']),
              'card_text'          => validColor($in['card_text'] ?? null, $defaults['card_text']),
              'card_header_bg'     => validColor($in['card_header_bg'] ?? null, $defaults['card_header_bg']),
              'card_header_text'   => validColor($in['card_header_text'] ?? null, $defaults['card_header_text']),
              'card_border'        => validColor($in['card_border'] ?? null, $defaults['card_border']),
              'table_row_bg'       => validColor($in['table_row_bg'] ?? null, $defaults['table_row_bg']),
              'table_row_alt_bg'   => validColor($in['table_row_alt_bg'] ?? null, $defaults['table_row_alt_bg']),
              'table_row_hover_bg' => validColor($in['table_row_hover_bg'] ?? null, $defaults['table_row_hover_bg']),
              'input_bg'           => validColor($in['input_bg'] ?? null, $defaults['input_bg']),
              'input_text'         => validColor($in['input_text'] ?? null, $defaults['input_text']),
              'input_border'       => validColor($in['input_border'] ?? null, $defaults['input_border']),
              'input_focus'        => validColor($in['input_focus'] ?? null, $defaults['input_focus']),
              'btn_primary_bg'     => validColor($in['btn_primary_bg'] ?? null, $defaults['btn_primary_bg']),
              'btn_primary_text'   => validColor($in['btn_primary_text'] ?? null, $defaults['btn_primary_text']),
            ];

            $sql = "
              INSERT INTO user_settings (
                user_id, navbar_bg, navbar_text, body_bg, body_text,
                table_header_bg, table_header_text, link_color,
                card_bg, card_text, card_header_bg, card_header_text, card_border,
                table_row_bg, table_row_alt_bg, table_row_hover_bg,
                input_bg, input_text, input_border, input_focus,
                btn_primary_bg, btn_primary_text
              ) VALUES (
                :u, :navbar_bg, :navbar_text, :body_bg, :body_text,
                :table_header_bg, :table_header_text, :link_color,
                :card_bg, :card_text, :card_header_bg, :card_header_text, :card_border,
                :table_row_bg, :table_row_alt_bg, :table_row_hover_bg,
                :input_bg, :input_text, :input_border, :input_focus,
                :btn_primary_bg, :btn_primary_text
              )
              ON CONFLICT(user_id) DO UPDATE SET
                navbar_bg=:navbar_bg, navbar_text=:navbar_text, body_bg=:body_bg, body_text=:body_text,
                table_header_bg=:table_header_bg, table_header_text=:table_header_text, link_color=:link_color,
                card_bg=:card_bg, card_text=:card_text, card_header_bg=:card_header_bg, card_header_text=:card_header_text, card_border=:card_border,
                table_row_bg=:table_row_bg, table_row_alt_bg=:table_row_alt_bg, table_row_hover_bg=:table_row_hover_bg,
                input_bg=:input_bg, input_text=:input_text, input_border=:input_border, input_focus=:input_focus,
                btn_primary_bg=:btn_primary_bg, btn_primary_text=:btn_primary_text
            ";
            $p = array_combine(
              array_map(fn($k)=>":$k", array_keys($data)),
              array_values($data)
            );
            $p[':u'] = $userId;
            $pdo->prepare($sql)->execute($p);

            Util::addFlash('success', 'Thème enregistré.');
            Util::redirect('settings.php');
        }

        if ($action === 'reset_theme') {
            $pdo->prepare("DELETE FROM user_settings WHERE user_id=:u")->execute([':u'=>$userId]);
            Util::addFlash('success', 'Thème réinitialisé.');
            Util::redirect('settings.php');
        }

        /* ---- Catégories ---- */

        if ($action === 'create_categories_table') {
            if ($hasCategories) {
                Util::addFlash('info', 'La table catégories existe déjà.');
                Util::redirect('settings.php');
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec("
                  CREATE TABLE IF NOT EXISTS categories (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    user_id INTEGER,
                    type TEXT
                  );
                ");
                $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_categories_user_name ON categories(user_id, name);");
                // Par défaut, 'debit' si null
                $pdo->exec("UPDATE categories SET type='debit' WHERE type IS NULL OR type=''");
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            Util::addFlash('success', 'Table catégories créée.');
            Util::redirect('settings.php');
        }

        if ($action === 'add_category') {
            if (!$hasCategories) throw new RuntimeException("La table catégories n'existe pas.");
            $name = trim((string)($_POST['name'] ?? ''));
            $type = (string)($_POST['type'] ?? 'debit');
            if (!in_array($type, ['credit','debit'], true)) $type = 'debit';
            if ($name === '') throw new RuntimeException("Nom de catégorie requis.");

            $sql = "INSERT INTO categories(name".($catHasUser? ",user_id" : "").", type) VALUES (:n".($catHasUser? ",:u" : "").", :t)";
            $p   = [':n'=>$name, ':t'=>$type];
            if ($catHasUser) $p[':u'] = $userId;

            $pdo->prepare($sql)->execute($p);
            Util::addFlash('success', 'Catégorie ajoutée.');
            Util::redirect('settings.php');
        }

        if ($action === 'update_category') {
            if (!$hasCategories) throw new RuntimeException("La table catégories n'existe pas.");
            $cid  = (int)($_POST['category_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $type = (string)($_POST['type'] ?? 'debit');
            if (!in_array($type, ['credit','debit'], true)) $type = 'debit';
            if ($cid <= 0) throw new RuntimeException("Catégorie invalide.");
            if ($name === '') throw new RuntimeException("Nom de catégorie requis.");

            // sécurité appartenance
            if ($catHasUser) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE id=:id AND user_id=:u");
                $st->execute([':id'=>$cid, ':u'=>$userId]);
                if ((int)$st->fetchColumn() === 0) throw new RuntimeException("Catégorie introuvable.");
            }

            $sql = "UPDATE categories SET name=:n, type=:t WHERE id=:id";
            $p   = [':n'=>$name, ':t'=>$type, ':id'=>$cid];
            if ($catHasUser) { $sql .= " AND user_id=:u"; $p[':u'] = $userId; }
            $pdo->prepare($sql)->execute($p);

            Util::addFlash('success', 'Catégorie mise à jour.');
            Util::redirect('settings.php');
        }

        if ($action === 'delete_category') {
            if (!$hasCategories) throw new RuntimeException("La table catégories n'existe pas.");
            $cid = (int)($_POST['category_id'] ?? 0);
            if ($cid <= 0) throw new RuntimeException("Catégorie invalide.");

            // sécurité appartenance
            if ($catHasUser) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE id=:id AND user_id=:u");
                $st->execute([':id'=>$cid, ':u'=>$userId]);
                if ((int)$st->fetchColumn() === 0) throw new RuntimeException("Catégorie introuvable.");
            }

            $pdo->beginTransaction();
            try {
                // Suppression en cascade des transactions liées à cette catégorie (si colonne présente)
                if ($trxHasCat) {
                    $sqlDelTx = "DELETE FROM transactions WHERE category_id = :c";
                    $p = [':c'=>$cid];
                    if ($trxHasUser) { $sqlDelTx .= " AND user_id = :u"; $p[':u'] = $userId; }
                    $pdo->prepare($sqlDelTx)->execute($p);
                }

                // Supprimer la catégorie
                $sqlDelCat = "DELETE FROM categories WHERE id = :id";
                $p2 = [':id'=>$cid];
                if ($catHasUser) { $sqlDelCat .= " AND user_id = :u"; $p2[':u'] = $userId; }
                $pdo->prepare($sqlDelCat)->execute($p2);

                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

            Util::addFlash('success', 'Catégorie et transactions liées supprimées.');
            Util::redirect('settings.php');
        }

        throw new RuntimeException('Action inconnue.');
    } catch (Throwable $e) {
        Util::addFlash('danger', $e->getMessage());
        Util::redirect('settings.php'.(isset($_POST['return']) ? ('?'.$_POST['return']) : ''));
    }
    exit;
}

/* Vue: charger paramètres thème à afficher */
$theme = array_merge($defaults, $theme);

/* Charger catégories pour affichage */
$categories = [];
if ($hasCategories) {
    $sql = "SELECT id, name, COALESCE(NULLIF(type,''),'debit') AS type FROM categories";
    $p = [];
    if ($catHasUser) { $sql .= " WHERE user_id = :u"; $p[':u'] = $userId; }
    $sql .= " ORDER BY name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $categories = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Réglage</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.color-cell{width:2.5rem;height:2.5rem;border:1px solid #ccc;border-radius:.25rem;display:inline-block;}</style>
</head>
<body>
<?php include __DIR__.'/_nav.php'; ?>

<div class="container py-3">

  <?php foreach (Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <strong>Thème (couleurs)</strong>
          <form method="post" class="m-0" onsubmit="return confirm('Réinitialiser le thème par défaut ?');">
            <?= Util::csrfInput() ?>
            <input type="hidden" name="action" value="reset_theme">
            <button class="btn btn-sm btn-outline-danger">Réinitialiser</button>
          </form>
        </div>
        <div class="card-body">
          <form method="post" class="row g-3">
            <?= Util::csrfInput() ?>
            <input type="hidden" name="action" value="save_theme">

            <div class="col-12"><h6 class="text-muted">Bandeau</h6></div>
            <div class="col-6">
              <label class="form-label">Fond</label>
              <input type="color" class="form-control form-control-color" name="navbar_bg" value="<?= h($theme['navbar_bg']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Texte/Boutons</label>
              <input type="color" class="form-control form-control-color" name="navbar_text" value="<?= h($theme['navbar_text']) ?>">
            </div>

            <div class="col-12"><h6 class="text-muted mt-2">Page</h6></div>
            <div class="col-6">
              <label class="form-label">Fond</label>
              <input type="color" class="form-control form-control-color" name="body_bg" value="<?= h($theme['body_bg']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Texte</label>
              <input type="color" class="form-control form-control-color" name="body_text" value="<?= h($theme['body_text']) ?>">
            </div>

            <div class="col-12"><h6 class="text-muted mt-2">Fenêtres (cartes)</h6></div>
            <div class="col-6">
              <label class="form-label">Fond</label>
              <input type="color" class="form-control form-control-color" name="card_bg" value="<?= h($theme['card_bg']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Texte</label>
              <input type="color" class="form-control form-control-color" name="card_text" value="<?= h($theme['card_text']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">En‑tête (fond)</label>
              <input type="color" class="form-control form-control-color" name="card_header_bg" value="<?= h($theme['card_header_bg']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">En‑tête (texte)</label>
              <input type="color" class="form-control form-control-color" name="card_header_text" value="<?= h($theme['card_header_text']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Bordure</label>
              <input type="color" class="form-control form-control-color" name="card_border" value="<?= h($theme['card_border']) ?>">
            </div>

            <div class="col-12"><h6 class="text-muted mt-2">Tableaux</h6></div>
            <div class="col-6">
              <label class="form-label">En‑tête (fond)</label>
              <input type="color" class="form-control form-control-color" name="table_header_bg" value="<?= h($theme['table_header_bg']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">En‑tête (texte)</label>
              <input type="color" class="form-control form-control-color" name="table_header_text" value="<?= h($theme['table_header_text']) ?>">
            </div>
            <div class="col-4">
              <label class="form-label">Ligne</label>
              <input type="color" class="form-control form-control-color" name="table_row_bg" value="<?= h($theme['table_row_bg']) ?>">
            </div>
            <div class="col-4">
              <label class="form-label">Zébrage</label>
              <input type="color" class="form-control form-control-color" name="table_row_alt_bg" value="<?= h($theme['table_row_alt_bg']) ?>">
            </div>
            <div class="col-4">
              <label class="form-label">Survol</label>
              <input type="color" class="form-control form-control-color" name="table_row_hover_bg" value="<?= h($theme['table_row_hover_bg']) ?>">
            </div>

            <div class="col-12"><h6 class="text-muted mt-2">Formulaires</h6></div>
            <div class="col-3">
              <label class="form-label">Fond</label>
              <input type="color" class="form-control form-control-color" name="input_bg" value="<?= h($theme['input_bg']) ?>">
            </div>
            <div class="col-3">
              <label class="form-label">Texte</label>
              <input type="color" class="form-control form-control-color" name="input_text" value="<?= h($theme['input_text']) ?>">
            </div>
            <div class="col-3">
              <label class="form-label">Bordure</label>
              <input type="color" class="form-control form-control-color" name="input_border" value="<?= h($theme['input_border']) ?>">
            </div>
            <div class="col-3">
              <label class="form-label">Focus</label>
              <input type="color" class="form-control form-control-color" name="input_focus" value="<?= h($theme['input_focus']) ?>">
            </div>

            <div class="col-12"><h6 class="text-muted mt-2">Actions</h6></div>
            <div class="col-6">
              <label class="form-label">Bouton principal (fond)</label>
              <input type="color" class="form-control form-control-color" name="btn_primary_bg" value="<?= h($theme['btn_primary_bg']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Bouton principal (texte)</label>
              <input type="color" class="form-control form-control-color" name="btn_primary_text" value="<?= h($theme['btn_primary_text']) ?>">
            </div>

            <div class="col-12"><h6 class="text-muted mt-2">Liens</h6></div>
            <div class="col-6">
              <label class="form-label">Couleur des liens</label>
              <input type="color" class="form-control form-control-color" name="link_color" value="<?= h($theme['link_color']) ?>">
            </div>

            <div class="col-12 mt-2 d-flex gap-2">
              <button class="btn btn-primary">Enregistrer</button>
              <span class="ms-2 text-muted small">Astuce: Ctrl+F5 si le navigateur garde l’ancien style.</span>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <strong>Catégories</strong>
          <?php if (!$hasCategories): ?>
            <form method="post" class="m-0">
              <?= Util::csrfInput() ?>
              <input type="hidden" name="action" value="create_categories_table">
              <button class="btn btn-sm btn-outline-primary">Créer la table</button>
            </form>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (!$hasCategories): ?>
            <div class="alert alert-warning mb-0">
              La table <code>categories</code> n'existe pas encore. Cliquez sur “Créer la table”.
              <?php if (!$trxHasCat): ?>
                <br><small>Note: la table <code>transactions</code> n’a pas de colonne <code>category_id</code>. Les transactions ne seront pas liées aux catégories.</small>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <!-- Ajout -->
            <form method="post" class="row g-2 mb-3">
              <?= Util::csrfInput() ?>
              <input type="hidden" name="action" value="add_category">
              <div class="col-7">
                <input type="text" name="name" class="form-control form-control-sm" placeholder="Nouvelle catégorie" required>
              </div>
              <div class="col-3">
                <select name="type" class="form-select form-select-sm">
                  <option value="debit">Débit</option>
                  <option value="credit">Crédit</option>
                </select>
              </div>
              <div class="col-2">
                <button class="btn btn-primary btn-sm w-100">Ajouter</button>
              </div>
            </form>

            <!-- Liste -->
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Nom</th>
                    <th>Type</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$categories): ?>
                    <tr><td colspan="3" class="text-muted">Aucune catégorie.</td></tr>
                  <?php else: foreach ($categories as $c): ?>
                    <?php if ($editId === (int)$c['id']): ?>
                      <!-- Ligne édition -->
                      <tr>
                        <td>
                          <form method="post" class="row g-2 align-items-center">
                            <?= Util::csrfInput() ?>
                            <input type="hidden" name="action" value="update_category">
                            <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                            <div class="col-12">
                              <input type="text" name="name" class="form-control form-control-sm" value="<?= h($c['name']) ?>" required>
                            </div>
                        </td>
                        <td style="width:160px">
                            <select name="type" class="form-select form-select-sm">
                              <option value="debit"  <?= ($c['type']==='debit')?'selected':'' ?>>Débit</option>
                              <option value="credit" <?= ($c['type']==='credit')?'selected':'' ?>>Crédit</option>
                            </select>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-primary">Enregistrer</button>
                            <a class="btn btn-sm btn-outline-secondary" href="settings.php">Annuler</a>
                          </form>
                        </td>
                      </tr>
                    <?php else: ?>
                      <!-- Ligne affichage -->
                      <tr>
                        <td><?= h($c['name']) ?></td>
                        <td><?= $c['type']==='credit' ? 'Crédit' : 'Débit' ?></td>
                        <td class="text-end">
                          <a class="btn btn-sm btn-outline-secondary" href="settings.php?edit=<?= (int)$c['id'] ?>">Modifier</a>
                          <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette catégorie ET toutes les transactions qui lui sont liées ?');">
                            <?= Util::csrfInput() ?>
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger">Supprimer</button>
                          </form>
                        </td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>

          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>