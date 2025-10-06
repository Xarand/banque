<?php declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\{Util,Database,FinanceRepository};

Util::startSession();
Util::requireAuth();

$db = new Database();
$repo = new FinanceRepository($db);
$userId = Util::currentUserId();

$errorAccount = null;
$errorTx = null;

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
            throw new RuntimeException("Date invalide.");
        }
        $rawAmount = str_replace(',','.', trim($_POST['amount'] ?? ''));
        if (!is_numeric($rawAmount)) {
            throw new RuntimeException("Montant invalide.");
        }
        $amount = (float)$rawAmount;
        if ($amount == 0.0) {
            throw new RuntimeException("Montant nul interdit.");
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

/* Transactions récentes (avec notes) */
$pdo = $db->pdo();
$txStmt = $pdo->prepare("
  SELECT t.id,t.date,t.description,t.amount,t.notes,
         a.name AS account,
         c.name AS category
  FROM transactions t
  JOIN accounts a ON a.id=t.account_id AND a.user_id=t.user_id
  LEFT JOIN categories c ON c.id=t.category_id
  WHERE t.user_id=:u
  ORDER BY date(t.date) DESC, t.id DESC
  LIMIT 30
");
$txStmt->execute([':u'=>$userId]);
$transactions = $txStmt->fetchAll();
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
</style>
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Tableau de bord</h1>
    <a class="btn btn-outline-secondary btn-sm" href="logout.php">Déconnexion</a>
  </div>

  <?php foreach(App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= App\Util::h($fl['type']) ?> py-2 mb-2"><?= App\Util::h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <div class="col-md-6">
      <div class="card shadow-sm mb-3">
        <div class="card-header py-2"><strong>Comptes</strong></div>
        <div class="card-body">
          <?php if($errorAccount): ?><div class="alert alert-danger py-1 mb-2"><?= App\Util::h($errorAccount) ?></div><?php endif; ?>
          <form method="post" class="row row-cols-lg-auto g-2 align-items-end mb-3">
            <?= App\Util::csrfInput() ?>
            <input type="hidden" name="form" value="account">
            <div class="col-12">
              <label class="form-label mb-0">Nom</label>
              <input name="name" class="form-control form-control-sm" required>
            </div>
            <div class="col-12">
              <button class="btn btn-primary btn-sm">Ajouter</button>
            </div>
          </form>
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Nom</th><th class="text-end" style="width:120px;">Solde</th></tr></thead>
            <tbody>
            <?php foreach($accounts as $a): ?>
              <tr>
                <td><?= App\Util::h($a['name']) ?></td>
                <td class="text-end"><?= number_format((float)$a['current_balance'],2,',',' ') ?> €</td>
              </tr>
            <?php endforeach; ?>
            <?php if(!$accounts): ?>
              <tr><td colspan="2"><em>Aucun compte</em></td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Catégories</strong></div>
        <div class="card-body">
          <form method="post" class="row row-cols-lg-auto g-2 align-items-end mb-3">
            <?= App\Util::csrfInput() ?>
            <input type="hidden" name="form" value="category">
            <div class="col-12">
              <label class="form-label mb-0">Nom</label>
              <input name="cat_name" class="form-control form-control-sm" required>
            </div>
            <div class="col-12">
              <button class="btn btn-secondary btn-sm">Ajouter</button>
            </div>
          </form>
          <ul class="list-group list-group-sm">
            <?php foreach($categories as $c): ?>
              <li class="list-group-item py-1"><?= App\Util::h($c['name']) ?></li>
            <?php endforeach; ?>
            <?php if(!$categories): ?>
              <li class="list-group-item py-1"><em>Aucune catégorie</em></li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card shadow-sm mb-3">
        <div class="card-header py-2"><strong>Nouvelle transaction</strong></div>
        <div class="card-body">
          <?php if($errorTx): ?><div class="alert alert-danger py-1 mb-2"><?= App\Util::h($errorTx) ?></div><?php endif; ?>
          <?php if(!$accounts): ?>
            <p class="text-muted mb-0"><em>Crée d'abord un compte.</em></p>
          <?php else: ?>
          <form method="post" class="row g-2">
            <?= App\Util::csrfInput() ?>
            <input type="hidden" name="form" value="tx">
            <div class="col-6">
              <label class="form-label mb-0">Compte</label>
              <select name="account_id" class="form-select form-select-sm">
                <?php foreach($accounts as $a): ?>
                  <option value="<?= (int)$a['id'] ?>"><?= App\Util::h($a['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label mb-0">Date</label>
              <input type="date" name="date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <?php if($categories): ?>
            <div class="col-6">
              <label class="form-label mb-0">Catégorie</label>
              <select name="category_id" class="form-select form-select-sm">
                <option value="">(Aucune)</option>
                <?php foreach($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= App\Util::h($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <div class="col-6">
              <label class="form-label mb-0">Montant</label>
              <input type="text" name="amount" class="form-control form-control-sm" required placeholder="123.45">
            </div>
            <div class="col-12">
              <label class="form-label mb-0">Description</label>
              <input type="text" name="description" class="form-control form-control-sm">
            </div>
            <div class="col-12">
              <label class="form-label mb-0">Notes</label>
              <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Détails internes, référence facture..."></textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-success btn-sm">Ajouter</button>
            </div>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Dernières transactions</strong></div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th style="width:90px;">Date</th>
                <th>Compte</th>
                <th>Cat</th>
                <th>Description</th>
                <th class="notes-cell">Notes</th>
                <th class="text-end" style="width:120px;">Montant</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($transactions as $t): ?>
              <tr>
                <td><?= App\Util::h($t['date']) ?></td>
                <td><?= App\Util::h($t['account']) ?></td>
                <td><?= $t['category'] ? App\Util::h($t['category']) : '' ?></td>
                <td><?= App\Util::h($t['description'] ?? '') ?></td>
                <td class="notes-cell" title="<?= App\Util::h($t['notes'] ?? '') ?>">
                  <?= App\Util::h(mb_strimwidth((string)($t['notes'] ?? ''),0,40,'…','UTF-8')) ?>
                </td>
                <td class="text-end <?= $t['amount']<0?'text-danger':'text-success' ?>">
                  <?= number_format((float)$t['amount'],2,',',' ') ?> €
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if(!$transactions): ?>
              <tr><td colspan="6"><em>Aucune transaction</em></td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>

</div>
</body>
</html>