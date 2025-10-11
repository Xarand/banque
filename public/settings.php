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

// Helpers
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

/*
  Réglages: Catégories uniquement (Thème séparé).
*/

// Présence des tables/colonnes
$hasCategories = hasTable($pdo, 'categories');
$trxHasUser    = hasTable($pdo, 'transactions') && hasCol($pdo, 'transactions', 'user_id');
$trxHasCat     = hasTable($pdo, 'transactions') && hasCol($pdo, 'transactions', 'category_id');
$catHasUser    = $hasCategories ? hasCol($pdo, 'categories', 'user_id') : false;

// S’assure que la colonne type (credit|debit) existe si la table existe
if ($hasCategories && !hasCol($pdo, 'categories', 'type')) {
    ensureColumn($pdo, 'categories', 'type', "TEXT");
    $pdo->exec("UPDATE categories SET type='debit' WHERE type IS NULL OR type=''");
}

// POST (inchangé)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_categories_table') {
            if ($hasCategories) { Util::addFlash('info', 'La table catégories existe déjà.'); Util::redirect('settings.php'); }
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

            if ($catHasUser) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE id=:id AND user_id=:u");
                $st->execute([':id'=>$cid, ':u'=>$userId]);
                if ((int)$st->fetchColumn() === 0) throw new RuntimeException("Catégorie introuvable.");
            }

            $pdo->beginTransaction();
            try {
                if ($trxHasCat) {
                    $sqlDelTx = "DELETE FROM transactions WHERE category_id = :c";
                    $p = [':c'=>$cid];
                    if ($trxHasUser) { $sqlDelTx .= " AND user_id = :u"; $p[':u'] = $userId; }
                    $pdo->prepare($sqlDelTx)->execute($p);
                }
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

// Charger catégories
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
<title>Réglages — Catégories</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__.'/_head_assets.php'; ?>
</head>
<body>
<?php include __DIR__.'/_nav.php'; ?>

<div class="container py-3">

  <?php foreach (Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <div>
      Les réglages de <strong>Thème</strong> ont été déplacés dans l’onglet dédié.
      <span class="text-muted">Ici, vous ne gérez plus que les catégories.</span>
    </div>
    <?php if (is_file(__DIR__.'/settings_theme.php')): ?>
      <a class="btn btn-sm btn-outline-primary" href="settings_theme.php">Ouvrir l’onglet Thème</a>
    <?php endif; ?>
  </div>

  <div class="row g-4">
    <div class="col-lg-6">
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
              <?php if (!hasTable($pdo,'transactions') || !$trxHasCat): ?>
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

    <div class="col-lg-6">
      <!-- Espace réservé à d’autres réglages futurs -->
    </div>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>