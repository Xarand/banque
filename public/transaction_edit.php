<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\{Util, Database, FinanceRepository};

Util::startSession();
Util::requireAuth();

$db   = new Database();
$repo = new FinanceRepository($db);
$userId = Util::currentUserId();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    Util::addFlash('danger','ID invalide.');
    Util::redirect('index.php');
}

$tx = $repo->getTransactionById($userId, $id);
if (!$tx) {
    Util::addFlash('danger','Transaction introuvable.');
    Util::redirect('index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();
        $accountId = (int)($_POST['account_id'] ?? 0);
        $date = trim($_POST['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
            throw new \RuntimeException("Date invalide.");
        }
        $rawAmount = str_replace(',','.', trim($_POST['amount'] ?? ''));
        if (!is_numeric($rawAmount)) {
            throw new \RuntimeException("Montant invalide.");
        }
        $amount = (float)$rawAmount;
        if ($amount == 0.0) {
            throw new \RuntimeException("Montant nul interdit.");
        }
        $desc  = trim($_POST['description'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
        $direction = $_POST['direction'] ?? null;
        if ($direction !== null && !in_array($direction, ['credit','debit'], true)) {
            $direction = null;
        }

        $repo->updateTransaction(
            $userId,
            $id,
            $accountId,
            $date,
            $amount,
            $desc,
            $categoryId,
            $notes,
            $direction
        );
        Util::addFlash('success','Transaction mise à jour.');
        Util::redirect('index.php');
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$accounts   = $repo->listAccounts($userId);
$categories = $repo->listCategories($userId);

function sel($a,$b){ return (string)$a===(string)$b ? 'selected' : ''; }

$currentDirection = $tx['amount'] < 0 ? 'debit' : 'credit';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Éditer transaction</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.badge-dir { font-size:0.65rem; }
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const catSelect = document.querySelector('select[name="category_id"]');
  const dirRadios = document.querySelectorAll('input[name="direction"]');
  const autoInfo  = document.getElementById('direction-auto-info');

  function updateDir() {
    const opt = catSelect.options[catSelect.selectedIndex];
    const type = opt ? opt.getAttribute('data-type') : '';
    if (type === 'income' || type === 'expense') {
      dirRadios.forEach(r => r.disabled = true);
      autoInfo.textContent = type === 'income'
        ? 'Catégorie revenu : le système force Crédit.'
        : 'Catégorie dépense : le système force Débit.';
      autoInfo.classList.remove('d-none');
    } else {
      dirRadios.forEach(r => r.disabled = false);
      autoInfo.classList.add('d-none');
    }
  }
  catSelect.addEventListener('change', updateDir);
  updateDir();
});
</script>
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:720px;">
  <h1 class="h4 mb-3">Éditer transaction #<?= (int)$tx['id'] ?></h1>

  <?php foreach(App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= App\Util::h($fl['type']) ?> py-2 mb-2"><?= App\Util::h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <?php if($error): ?>
    <div class="alert alert-danger py-2 mb-3"><?= App\Util::h($error) ?></div>
  <?php endif; ?>

  <form method="post" class="card p-3 shadow-sm">
    <?= App\Util::csrfInput() ?>
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Date</label>
        <input type="date" name="date" value="<?= App\Util::h($tx['date']) ?>" class="form-control" required>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Montant</label>
        <input type="text" name="amount" value="<?= App\Util::h((string)abs($tx['amount'])) ?>" class="form-control" required>
        <small class="text-muted">
          Saisir la valeur absolue : le sens sera appliqué automatiquement si catégorie typée.
        </small>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Compte</label>
        <select name="account_id" class="form-select" required>
          <?php foreach($accounts as $acc): ?>
            <option value="<?= (int)$acc['id'] ?>" <?= sel($acc['id'],$tx['account_id']) ?>>
              <?= App\Util::h($acc['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label">Catégorie (optionnel)</label>
      <select name="category_id" class="form-select">
        <option value="">(Aucune)</option>
        <?php foreach($categories as $cat): ?>
          <option value="<?= (int)$cat['id'] ?>"
                  data-type="<?= App\Util::h($cat['type'] ?? '') ?>"
                  <?= sel($cat['id'], $tx['category_id']) ?>>
            <?= App\Util::h($cat['name']) ?>
            <?= $cat['type']==='income' ? ' (Revenu)' : ($cat['type']==='expense' ? ' (Dépense)' : '') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="mb-3">
      <label class="form-label d-block">Sens (si pas de catégorie typée)</label>
      <div class="d-flex gap-4">
        <label class="form-check">
          <input type="radio" name="direction" value="credit" class="form-check-input"
            <?= $currentDirection==='credit' ? 'checked' : '' ?>>
          Crédit
        </label>
        <label class="form-check">
          <input type="radio" name="direction" value="debit" class="form-check-input"
            <?= $currentDirection==='debit' ? 'checked' : '' ?>>
          Débit
        </label>
      </div>
      <div id="direction-auto-info" class="small text-muted mt-1 d-none"></div>
    </div>

    <div class="mb-3">
      <label class="form-label">Description</label>
      <input type="text" name="description" class="form-control" value="<?= App\Util::h($tx['description'] ?? '') ?>">
    </div>

    <div class="mb-3">
      <label class="form-label">Notes</label>
      <textarea name="notes" rows="3" class="form-control"><?= App\Util::h($tx['notes'] ?? '') ?></textarea>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary">Enregistrer</button>
      <a href="index.php" class="btn btn-outline-secondary">Annuler</a>
    </div>
  </form>
</div>
</body>
</html>