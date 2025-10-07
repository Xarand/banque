<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\{Util, Database, FinanceRepository};

Util::startSession();
Util::requireAuth();

$db        = new Database();
$repo      = new FinanceRepository($db);
$userId    = Util::currentUserId();

$errorAccount = null;
$errorTx      = null;

/* Suppression transaction */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_tx') {
    try {
        Util::checkCsrf();
        $txId = (int)($_POST['tx_id'] ?? 0);
        if ($txId > 0) {
            $repo->deleteTransaction($userId, $txId);
            Util::addFlash('success', 'Transaction supprimée.');
        }
        Util::redirect('index.php');
    } catch (Throwable $e) {
        Util::addFlash('danger', $e->getMessage());
        Util::redirect('index.php');
    }
}

/* Création compte */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'account') {
    try {
        Util::checkCsrf();
        $repo->createAccount($userId, $_POST['name'] ?? '');
        Util::addFlash('success', 'Compte créé.');
        Util::redirect('index.php');
    } catch (Throwable $e) {
        $errorAccount = $e->getMessage();
    }
}

/* Création catégorie */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'category') {
    try {
        Util::checkCsrf();
        $type = $_POST['cat_type'] ?? null;
        if ($type === '') $type = null;
        $repo->createCategory($userId, $_POST['cat_name'] ?? '', $type);
        Util::addFlash('success', 'Catégorie créée.');
        Util::redirect('index.php');
    } catch (Throwable $e) {
        $errorAccount = $e->getMessage();
    }
}

/* Ajout transaction */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'tx') {
    try {
        Util::checkCsrf();

        $accountId = (int)($_POST['account_id'] ?? 0);
        $date      = trim($_POST['date'] ?? '');
        if ($date === '') $date = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException("Date invalide.");
        }
        $rawAmount = str_replace(',', '.', trim($_POST['amount'] ?? ''));
        if (!is_numeric($rawAmount)) {
            throw new RuntimeException("Montant invalide.");
        }
        $amount = (float)$rawAmount;
        if ($amount == 0.0) {
            throw new RuntimeException("Montant nul interdit.");
        }

        $desc       = trim($_POST['description'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');
        $categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
        $direction  = $_POST['direction'] ?? null;
        if ($direction !== null && !in_array($direction, ['credit','debit'], true)) {
            $direction = null;
        }

        $repo->addTransaction(
            $userId,
            $accountId,
            $date,
            $amount,
            $desc,
            $categoryId,
            $notes,
            $direction
        );

        Util::addFlash('success', 'Transaction ajoutée.');
        Util::redirect('index.php');
    } catch (Throwable $e) {
        $errorTx = $e->getMessage();
    }
}

/* Données */
$accounts   = $repo->listAccounts($userId);
$categories = $repo->listCategories($userId);

/* Total tous comptes */
$totalAll = 0.0;
foreach ($accounts as $a) {
    $totalAll += (float)$a['current_balance'];
}

/* Filtres */
$filterAccountId  = isset($_GET['account_id']) && $_GET['account_id'] !== '' ? (int)$_GET['account_id'] : null;
$filterCategoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
$dateFrom         = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo           = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$validDate = static fn(string $d): bool => $d === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$validDate($dateFrom)) $dateFrom = '';
if (!$validDate($dateTo))   $dateTo   = '';

if ($filterAccountId) {
    $ok = false;
    foreach ($accounts as $a) {
        if ((int)$a['id'] === $filterAccountId) { $ok = true; break; }
    }
    if (!$ok) $filterAccountId = null;
}
if ($filterCategoryId) {
    $ok = false;
    foreach ($categories as $c) {
        if ((int)$c['id'] === $filterCategoryId) { $ok = true; break; }
    }
    if (!$ok) $filterCategoryId = null;
}

$filters = [
    'account_id'  => $filterAccountId,
    'category_id' => $filterCategoryId,
    'date_from'   => $dateFrom ?: null,
    'date_to'     => $dateTo ?: null
];

$search       = $repo->searchTransactions($userId, $filters, 100);
$transactions = $search['rows'];
$txCount      = $search['count'];
$txSum        = $search['sum'];

function h(string $v): string { return App\Util::h($v); }

/* Export URL */
$query = [];
if ($filterAccountId !== null)  $query['account_id']  = $filterAccountId;
if ($filterCategoryId !== null) $query['category_id'] = $filterCategoryId;
if ($dateFrom !== '')           $query['date_from']   = $dateFrom;
if ($dateTo !== '')             $query['date_to']     = $dateTo;
$exportUrl = 'export_csv.php' . ($query ? ('?' . http_build_query($query)) : '');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Tableau de bord</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
<style>
.tx-badge-credit { background:#d1e7dd; color:#0f5132; }
.tx-badge-debit  { background:#f8d7da; color:#842029; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-md navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">Banque</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link active" href="index.php">Tableau</a></li>
        <li class="nav-item"><a class="nav-link" href="reports.php">Rapports</a></li>
        <li class="nav-item"><a class="nav-link" href="micro_index.php">Micro</a></li>
      </ul>
      <a href="logout.php" class="btn btn-sm btn-outline-light">Déconnexion</a>
    </div>
  </div>
</nav>

<div class="container pb-5">

  <?php foreach (App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="row">
    <!-- Colonne gauche -->
    <div class="col-lg-4 col-md-5">
      <h2 class="h6 mt-2">Nouveau compte</h2>
      <?php if($errorAccount): ?><div class="alert alert-danger py-1 mb-2"><?= h($errorAccount) ?></div><?php endif; ?>
      <form method="post" class="card p-2 mb-3 shadow-sm">
        <?= App\Util::csrfInput() ?>
        <input type="hidden" name="form" value="account">
        <div class="mb-2">
          <input name="name" class="form-control form-control-sm" placeholder="Nom du compte" required>
        </div>
        <button class="btn btn-sm btn-primary">Créer</button>
      </form>

      <h2 class="h6">Nouvelle catégorie</h2>
      <form method="post" class="card p-2 mb-3 shadow-sm">
        <?= App\Util::csrfInput() ?>
        <input type="hidden" name="form" value="category">
        <div class="mb-2">
          <input name="cat_name" class="form-control form-control-sm" placeholder="Nom catégorie" required>
        </div>
        <div class="mb-2">
          <select name="cat_type" class="form-select form-select-sm">
            <option value="">(Sans type)</option>
            <option value="income">Revenu</option>
            <option value="expense">Dépense</option>
          </select>
        </div>
        <button class="btn btn-sm btn-primary">Créer</button>
      </form>

      <h2 class="h6">Nouvelle transaction</h2>
      <?php if($errorTx): ?><div class="alert alert-danger py-1 mb-2"><?= h($errorTx) ?></div><?php endif; ?>
      <form method="post" class="card p-2 mb-4 shadow-sm" id="form-tx">
        <?= App\Util::csrfInput() ?>
        <input type="hidden" name="form" value="tx">

        <div class="mb-2">
          <label class="form-label mb-1">Compte</label>
          <select name="account_id" class="form-select form-select-sm" required>
            <?php foreach($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-2">
          <label class="form-label mb-1">Date</label>
          <input type="date" name="date" class="form-control form-control-sm" value="<?= h(date('Y-m-d')) ?>">
        </div>

        <div class="mb-2">
          <label class="form-label mb-1">Montant</label>
          <input name="amount" class="form-control form-control-sm" placeholder="Ex: 125.30" required>
        </div>

        <div class="mb-2">
          <label class="form-label mb-1">Catégorie</label>
          <select name="category_id" class="form-select form-select-sm" id="tx-category">
            <option value="">(Aucune)</option>
            <?php foreach($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>"
                      data-type="<?= h($c['type'] ?? '') ?>">
                <?= h($c['name']) ?>
                <?= $c['type']==='income' ? ' (Revenu)' : ($c['type']==='expense' ? ' (Dépense)' : '') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-2" id="direction-wrapper">
          <label class="form-label mb-1 d-block">Sens (si pas de catégorie typée)</label>
          <div class="d-flex gap-3">
            <label class="form-check">
              <input type="radio" name="direction" value="credit" class="form-check-input" checked>
              Crédit
            </label>
            <label class="form-check">
              <input type="radio" name="direction" value="debit" class="form-check-input">
              Débit
            </label>
          </div>
          <div id="direction-auto-info" class="small text-muted mt-1 d-none"></div>
        </div>

        <div class="mb-2">
          <label class="form-label mb-1">Description</label>
          <input name="description" class="form-control form-control-sm">
        </div>
        <div class="mb-2">
          <label class="form-label mb-1">Notes</label>
          <textarea name="notes" rows="2" class="form-control form-control-sm"></textarea>
        </div>

        <button class="btn btn-sm btn-success">Ajouter</button>
      </form>
    </div>

    <!-- Colonne droite -->
    <div class="col-lg-8 col-md-7">
      <h2 class="h6 mt-2">Transactions (filtrées)</h2>

      <!-- Total tous comptes + comptes micro -->
      <div class="alert alert-info py-2 mb-3">
        Total de tous les comptes :
        <strong><?= number_format($totalAll, 2, ',', ' ') ?> €</strong>
        <div class="small text-muted mt-2">
          <?php foreach ($accounts as $acc): ?>
            <?php if (!empty($acc['micro_enterprise_id'])): ?>
              <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle me-1">
                <?= h($acc['name']) ?> (Micro)
              </span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Formulaire filtres -->
      <form method="get" class="row g-2 align-items-end mb-2">
        <div class="col-6 col-sm-3">
          <label class="form-label mb-1">Compte</label>
          <select name="account_id" class="form-select form-select-sm">
            <option value="">(Tous)</option>
            <?php foreach($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= $filterAccountId===$a['id']?'selected':'' ?>>
                <?= h($a['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-sm-3">
          <label class="form-label mb-1">Catégorie</label>
          <select name="category_id" class="form-select form-select-sm">
            <option value="">(Toutes)</option>
            <?php foreach($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $filterCategoryId===$c['id']?'selected':'' ?>>
                <?= h($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-sm-2">
          <label class="form-label mb-1">Du</label>
          <input type="date" name="date_from" value="<?= h($dateFrom) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-sm-2">
          <label class="form-label mb-1">Au</label>
          <input type="date" name="date_to" value="<?= h($dateTo) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-sm-2 d-flex gap-2">
          <button class="btn btn-sm btn-outline-primary flex-grow-1">Filtrer</button>
          <?php if($filterAccountId || $filterCategoryId || $dateFrom || $dateTo): ?>
            <a href="index.php" class="btn btn-sm btn-outline-secondary" title="Réinitialiser">✕</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Export CSV -->
      <div class="mb-3">
        <a href="<?= h($exportUrl) ?>" class="btn btn-sm btn-outline-success">
          Exporter CSV (transactions filtrées)
        </a>
      </div>

      <div class="mb-2 small text-muted">
        <?= (int)$txCount ?> transaction(s), total :
        <strong class="<?= $txSum < 0 ? 'text-danger':'text-success' ?>">
          <?= number_format($txSum, 2, ',', ' ') ?>
        </strong>
      </div>

      <!-- Cartes (mobile) -->
      <div class="tx-cards">
        <?php foreach($transactions as $t): ?>
          <div class="tx-card">
            <div class="tx-actions">
              <a href="transaction_edit.php?id=<?= (int)$t['id'] ?>"
                 class="btn btn-sm btn-outline-primary btn-icon"
                 aria-label="Éditer">✎</a>
              <form method="post" class="d-inline" onsubmit="return confirmDelete(this);" aria-label="Supprimer transaction">
                <?= App\Util::csrfInput() ?>
                <input type="hidden" name="form" value="delete_tx">
                <input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-sm btn-outline-danger btn-icon" aria-label="Supprimer">🗑</button>
              </form>
            </div>
            <div class="fw-semibold"><?= h($t['date']) ?> • <?= h($t['account']) ?></div>
            <div class="text-muted mb-1">
              <?= h($t['category'] ?? '—') ?>
              <?php if ($t['amount'] >= 0): ?>
                <span class="badge tx-badge-credit ms-1">Crédit</span>
              <?php else: ?>
                <span class="badge tx-badge-debit ms-1">Débit</span>
              <?php endif; ?>
            </div>
            <?php if(($t['description'] ?? '') !== ''): ?>
              <div><?= h($t['description']) ?></div>
            <?php endif; ?>
            <div class="tx-amount <?= $t['amount'] < 0 ? 'text-danger':'text-success' ?>">
              <?= number_format($t['amount'], 2, ',', ' ') ?>
            </div>
            <?php if(($t['notes'] ?? '') !== ''): ?>
              <div class="text-muted" style="font-size:.7rem;"><?= h($t['notes']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; if(!$transactions): ?>
          <div class="text-muted">Aucune transaction.</div>
        <?php endif; ?>
      </div>

      <!-- Tableau desktop -->
      <div class="desktop-table-wrapper">
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th>Date</th>
                <th>Compte</th>
                <th>Catégorie</th>
                <th class="d-none d-md-table-cell">Type</th>
                <th>Description</th>
                <th class="text-end">Montant</th>
                <th class="d-none d-md-table-cell">Notes</th>
                <th class="actions-col">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($transactions as $t): ?>
              <tr>
                <td><?= h($t['date']) ?></td>
                <td><?= h($t['account']) ?></td>
                <td><?= h($t['category'] ?? '') ?></td>
                <td class="d-none d-md-table-cell">
                  <?php if ($t['amount'] >= 0): ?>
                    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle badge-dir">Crédit</span>
                  <?php else: ?>
                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle badge-dir">Débit</span>
                  <?php endif; ?>
                </td>
                <td><?= h($t['description'] ?? '') ?></td>
                <td class="amount <?= $t['amount'] < 0 ? 'text-danger':'text-success' ?>">
                  <?= number_format($t['amount'], 2, ',', ' ') ?>
                </td>
                <td class="notes-cell d-none d-md-table-cell" title="<?= h($t['notes'] ?? '') ?>">
                  <?= h($t['notes'] ?? '') ?>
                </td>
                <td class="actions-col">
                  <a href="transaction_edit.php?id=<?= (int)$t['id'] ?>"
                     class="btn btn-sm btn-outline-primary btn-icon"
                     aria-label="Éditer transaction <?= (int)$t['id'] ?>">✎</a>
                  <form method="post" class="d-inline" onsubmit="return confirmDelete(this);" aria-label="Supprimer transaction <?= (int)$t['id'] ?>">
                    <?= App\Util::csrfInput() ?>
                    <input type="hidden" name="form" value="delete_tx">
                    <input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger btn-icon" aria-label="Supprimer">🗑</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; if(!$transactions): ?>
              <tr><td colspan="8" class="text-muted">Aucune transaction.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
function confirmDelete(form){
  return confirm('Supprimer cette transaction ?');
}

document.addEventListener('DOMContentLoaded', () => {
  const catSelect = document.getElementById('tx-category');
  const dirRadios = document.querySelectorAll('input[name="direction"]');
  const infoAuto  = document.getElementById('direction-auto-info');

  function updateDirectionState() {
    if(!catSelect) return;
    const opt  = catSelect.options[catSelect.selectedIndex];
    const type = opt ? opt.getAttribute('data-type') : '';
    if (type === 'income' || type === 'expense') {
      dirRadios.forEach(r => { r.disabled = true; });
      infoAuto.textContent = type === 'income'
        ? "Catégorie revenu : montant forcé en Crédit."
        : "Catégorie dépense : montant forcé en Débit.";
      infoAuto.classList.remove('d-none');
    } else {
      dirRadios.forEach(r => { r.disabled = false; });
      infoAuto.classList.add('d-none');
    }
  }
  if (catSelect) {
    catSelect.addEventListener('change', updateDirectionState);
    updateDirectionState();
  }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>