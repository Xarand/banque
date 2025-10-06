<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\{Util, Database, FinanceRepository};

Util::startSession();
Util::requireAuth();

$db   = new Database();
$repo = new FinanceRepository($db);
$userId = Util::currentUserId();

$errorAccount = null;
$errorTx = null;

/* Suppression transaction */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form'] ?? '')==='delete_tx') {
    try {
        Util::checkCsrf();
        $txId = (int)($_POST['tx_id'] ?? 0);
        if ($txId > 0) {
            $repo->deleteTransaction($userId, $txId);
            Util::addFlash('success','Transaction supprimée.');
        }
        Util::redirect('index.php');
    } catch (Throwable $e) {
        Util::addFlash('danger',$e->getMessage());
        Util::redirect('index.php');
    }
}

/* Création compte */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form'] ?? '')==='account') {
    try {
        Util::checkCsrf();
        $repo->createAccount($userId, $_POST['name'] ?? '');
        Util::addFlash('success','Compte créé.');
        Util::redirect('index.php');
    } catch(Throwable $e) {
        $errorAccount = $e->getMessage();
    }
}

/* Création catégorie */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form'] ?? '')==='category') {
    try {
        Util::checkCsrf();
        $repo->createCategory($userId, $_POST['cat_name'] ?? '');
        Util::addFlash('success','Catégorie créée.');
        Util::redirect('index.php');
    } catch(Throwable $e) {
        $errorAccount = $e->getMessage();
    }
}

/* Ajout transaction */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form'] ?? '')==='tx') {
    try {
        Util::checkCsrf();
        $accountId = (int)($_POST['account_id'] ?? 0);
        $date = trim($_POST['date'] ?? '');
        if ($date === '') $date = date('Y-m-d');
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
        $desc = trim($_POST['description'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;

        $repo->addTransaction($userId,$accountId,$date,$amount,$desc,$categoryId,$notes);
        Util::addFlash('success','Transaction ajoutée.');
        Util::redirect('index.php');
    } catch(Throwable $e) {
        $errorTx = $e->getMessage();
    }
}

$accounts   = $repo->listAccounts($userId);
$categories = $repo->listCategories($userId);

/* --------- Filtres --------- */
$filterAccountId  = isset($_GET['account_id']) && $_GET['account_id'] !== '' ? (int)$_GET['account_id'] : null;
$filterCategoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo   = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$validDate = function(string $d): bool {
    return $d === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
};
if (!$validDate($dateFrom)) $dateFrom = '';
if (!$validDate($dateTo))   $dateTo = '';

// Validation appartenance compte
if ($filterAccountId) {
    $found = false;
    foreach ($accounts as $a) {
        if ((int)$a['id'] === $filterAccountId) { $found = true; break; }
    }
    if (!$found) $filterAccountId = null;
}
// Validation appartenance catégorie
if ($filterCategoryId) {
    $found = false;
    foreach ($categories as $c) {
        if ((int)$c['id'] === $filterCategoryId) { $found = true; break; }
    }
    if (!$found) $filterCategoryId = null;
}

$filters = [
    'account_id'  => $filterAccountId,
    'category_id' => $filterCategoryId,
    'date_from'   => $dateFrom ?: null,
    'date_to'     => $dateTo ?: null
];

$search = $repo->searchTransactions($userId, $filters, 100);
$transactions = $search['rows'];
$txCount = $search['count'];
$txSum   = $search['sum'];
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Tableau de bord</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
table td.amount { text-align:right; }
td.notes-cell { max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-size:0.75rem; color:#555; }
.actions-col { white-space:nowrap; }
</style>
<script>
function confirmDelete(form){
  if(confirm('Supprimer cette transaction ?')) { form.submit(); }
  return false;
}
</script>
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Tableau de bord</h1>
    <div>
      <a href="logout.php" class="btn btn-sm btn-outline-secondary">Déconnexion</a>
    </div>
  </div>

  <?php foreach(App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= App\Util::h($fl['type']) ?> py-2"><?= App\Util::h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="row">
    <div class="col-md-4">
      <h2 class="h6">Nouveau compte</h2>
      <?php if($errorAccount): ?><div class="alert alert-danger py-1 mb-2"><?= App\Util::h($errorAccount) ?></div><?php endif; ?>
      <form method="post" class="card p-2 mb-3">
        <?= App\Util::csrfInput() ?>
        <input type="hidden" name="form" value="account">
        <div class="mb-2">
          <input name="name" class="form-control form-control-sm" placeholder="Nom du compte" required>
        </div>
        <button class="btn btn-sm btn-primary">Créer</button>
      </form>

      <h2 class="h6">Nouvelle catégorie</h2>
      <form method="post" class="card p-2 mb-3">
        <?= App\Util::csrfInput() ?>
        <input type="hidden" name="form" value="category">
        <div class="mb-2">
          <input name="cat_name" class="form-control form-control-sm" placeholder="Nom catégorie" required>
        </div>
        <button class="btn btn-sm btn-primary">Créer</button>
      </form>

      <h2 class="h6">Nouvelle transaction</h2>
      <?php if($errorTx): ?><div class="alert alert-danger py-1 mb-2"><?= App\Util::h($errorTx) ?></div><?php endif; ?>
      <form method="post" class="card p-2 mb-3">
        <?= App\Util::csrfInput() ?>
        <input type="hidden" name="form" value="tx">
        <div class="mb-2">
          <label class="form-label mb-1">Compte</label>
          <select name="account_id" class="form-select form-select-sm" required>
            <?php foreach($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>"><?= App\Util::h($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label mb-1">Date</label>
            <input type="date" name="date" class="form-control form-control-sm" value="<?= App\Util::h(date('Y-m-d')) ?>">
        </div>
        <div class="mb-2">
          <label class="form-label mb-1">Montant</label>
          <input name="amount" class="form-control form-control-sm" placeholder="Ex: 125.30" required>
        </div>
        <div class="mb-2">
          <label class="form-label mb-1">Catégorie</label>
          <select name="category_id" class="form-select form-select-sm">
            <option value="">(Aucune)</option>
            <?php foreach($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= App\Util::h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
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

    <div class="col-md-8">
      <h2 class="h6">Transactions (filtrées)</h2>

      <form method="get" class="row g-2 align-items-end mb-2">
        <div class="col-sm-3">
          <label class="form-label mb-1">Compte</label>
          <select name="account_id" class="form-select form-select-sm">
            <option value="">(Tous)</option>
            <?php foreach($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= $filterAccountId===$a['id']?'selected':'' ?>>
                <?= App\Util::h($a['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3">
          <label class="form-label mb-1">Catégorie</label>
          <select name="category_id" class="form-select form-select-sm">
            <option value="">(Toutes)</option>
            <?php foreach($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $filterCategoryId===$c['id']?'selected':'' ?>>
                <?= App\Util::h($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-2">
          <label class="form-label mb-1">Du</label>
          <input type="date" name="date_from" value="<?= App\Util::h($dateFrom) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-sm-2">
          <label class="form-label mb-1">Au</label>
          <input type="date" name="date_to" value="<?= App\Util::h($dateTo) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-sm-2 d-flex gap-2">
          <button class="btn btn-sm btn-outline-primary flex-grow-1">Filtrer</button>
          <?php if($filterAccountId || $filterCategoryId || $dateFrom || $dateTo): ?>
            <a href="index.php" class="btn btn-sm btn-outline-secondary" title="Réinitialiser">✕</a>
          <?php endif; ?>
        </div>
      </form>

      <div class="mb-2 small text-muted">
        <?= (int)$txCount ?> transaction(s), total :
        <strong class="<?= $txSum<0?'text-danger':'text-success' ?>">
          <?= number_format($txSum, 2, ',', ' ') ?>
        </strong>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>Date</th>
              <th>Compte</th>
              <th>Catégorie</th>
              <th>Description</th>
              <th class="text-end">Montant</th>
              <th>Notes</th>
              <th class="actions-col">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($transactions as $t): ?>
            <tr>
              <td><?= App\Util::h($t['date']) ?></td>
              <td><?= App\Util::h($t['account']) ?></td>
              <td><?= App\Util::h($t['category'] ?? '') ?></td>
              <td><?= App\Util::h($t['description'] ?? '') ?></td>
              <td class="amount <?= $t['amount'] < 0 ? 'text-danger':'text-success' ?>">
                <?= number_format($t['amount'], 2, ',', ' ') ?>
              </td>
              <td class="notes-cell" title="<?= App\Util::h($t['notes'] ?? '') ?>">
                <?= App\Util::h($t['notes'] ?? '') ?>
              </td>
              <td class="actions-col">
                <a href="transaction_edit.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary">✎</a>
                <form method="post" class="d-inline" onsubmit="return confirmDelete(this);">
                  <?= App\Util::csrfInput() ?>
                  <input type="hidden" name="form" value="delete_tx">
                  <input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">🗑</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$transactions): ?>
            <tr><td colspan="7" class="text-muted">Aucune transaction.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</body>
</html>