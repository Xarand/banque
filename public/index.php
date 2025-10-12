<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database};

ini_set('display_errors','1'); // désactiver en prod si besoin
error_reporting(E_ALL);

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

function h(string $s): string { return App\Util::h($s); }
function fmt(float $n): string { return number_format($n, 2, ',', ' '); }

// Date -> JJ/MM/AAAA (pour affichage)
function frDate(?string $d): string {
    if (!$d) return '';
    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})~', $d, $m)) {
        return sprintf('%02d/%02d/%04d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    $ts = strtotime($d);
    return $ts ? date('d/m/Y', $ts) : $d;
}

function hasCol(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("PRAGMA table_info($table)");
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (strcasecmp((string)$c['name'], $col) === 0) return true;
    }
    return false;
}
$accHasUser = hasCol($pdo, 'accounts', 'user_id');
$trxHasUser = hasCol($pdo, 'transactions', 'user_id');
$trxHasCat  = hasCol($pdo, 'transactions', 'category_id');

// POST: suppression d'une transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'delete_tx') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID invalide.');

            // vérifier appartenance
            $sql = "SELECT account_id FROM transactions WHERE id=:id";
            $p = [':id'=>$id];
            if ($trxHasUser) { $sql .= " AND user_id=:u"; $p[':u'] = $userId; }
            $st = $pdo->prepare($sql);
            $st->execute($p);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Transaction introuvable.');

            $del = "DELETE FROM transactions WHERE id=:id";
            $pd  = [':id'=>$id];
            if ($trxHasUser) { $del .= " AND user_id=:u"; $pd[':u'] = $userId; }
            $pdo->prepare($del)->execute($pd);

            Util::addFlash('success', 'Transaction supprimée.');
            $return = trim((string)($_POST['return'] ?? ''));
            Util::redirect('index.php'.($return !== '' ? ('?'.$return) : ''));
        }

        throw new RuntimeException('Action inconnue.');
    } catch (Throwable $e) {
        Util::addFlash('danger', $e->getMessage());
        Util::redirect('index.php');
    }
    exit;
}

// Catégories disponibles ?
$hasCategories = false;
try {
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='categories' LIMIT 1");
    $st->execute();
    $hasCategories = (bool)$st->fetchColumn();
} catch (Throwable $e) {}

// Filtres
function parseDate(?string $s): ?string {
    if (!$s) return null;
    $s = trim($s);
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $s)) return $s;                  // YYYY-MM-DD
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $s, $m)) {                 // DD/MM/YYYY
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    return null;
}
$accountId  = isset($_GET['account_id']) ? (int)$_GET['account_id'] : null;
$categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$dateFrom   = parseDate($_GET['from'] ?? $_GET['du'] ?? null);
$dateTo     = parseDate($_GET['to']   ?? $_GET['au'] ?? null);
$type       = in_array(($_GET['type'] ?? ''), ['credit','debit'], true) ? $_GET['type'] : '';
$qSearch    = trim((string)($_GET['q'] ?? ''));

// Pagination
$perPage = (int)($_GET['pp'] ?? 50);
$perPage = max(10, min(200, $perPage));
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $perPage;

// Comptes (pour filtre) + soldes
$sqlAcc = "SELECT id, name".($accHasUser ? ", user_id" : "")." FROM accounts";
$paramsAcc = [];
if ($accHasUser) { $sqlAcc .= " WHERE user_id = :u"; $paramsAcc[':u'] = $userId; }
$sqlAcc .= " ORDER BY name ASC";
$stA = $pdo->prepare($sqlAcc);
$stA->execute($paramsAcc);
$accounts = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Soldes par compte + total
$balances = [];
$totalAllAccounts = 0.0;
if ($accounts) {
    $ids = array_map(fn($r)=>(int)$r['id'], $accounts);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $sqlBal = "SELECT t.account_id, ROUND(SUM(t.amount),2) AS bal
               FROM transactions t
               WHERE t.account_id IN ($in)";
    $bind = $ids;
    if ($trxHasUser) { $sqlBal .= " AND t.user_id = ?"; $bind[] = $userId; }
    $sqlBal .= " GROUP BY t.account_id";
    $stB = $pdo->prepare($sqlBal);
    $stB->execute($bind);
    foreach ($stB->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $balances[(int)$row['account_id']] = (float)($row['bal'] ?? 0);
    }
    foreach ($accounts as $a) {
        $aid = (int)$a['id'];
        $totalAllAccounts += (float)($balances[$aid] ?? 0.0);
    }
}

// Catégories pour filtre
$categories = [];
if ($hasCategories) {
    $stC = $pdo->prepare("SELECT id, name FROM categories ORDER BY name ASC");
    $stC->execute();
    $categories = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Requête Transactions
$cols = [
    "t.id", "t.date", "t.amount", "t.description", "t.notes",
    "a.name AS account"
];
if ($hasCategories && $trxHasCat) $cols[] = "c.name AS category";

$fromJoin = " FROM transactions t JOIN accounts a ON a.id = t.account_id ";
if ($hasCategories && $trxHasCat) $fromJoin .= " LEFT JOIN categories c ON c.id = t.category_id ";

$where = " WHERE 1=1 ";
$params = [];

if ($trxHasUser) { $where .= " AND t.user_id = :u"; $params[':u'] = $userId; }
if ($accHasUser) { $where .= " AND (a.user_id = :ua OR a.user_id IS NULL)"; $params[':ua'] = $userId; }

if ($accountId) {
    $where .= " AND t.account_id = :acc";
    $params[':acc'] = $accountId;
}
if ($hasCategories && $trxHasCat && $categoryId) {
    $where .= " AND t.category_id = :cat";
    $params[':cat'] = $categoryId;
}
if ($dateFrom) { $where .= " AND date(t.date) >= date(:df)"; $params[':df'] = $dateFrom; }
if ($dateTo)   { $where .= " AND date(t.date) <= date(:dt)"; $params[':dt'] = $dateTo; }
if ($type === 'credit') { $where .= " AND t.amount > 0"; }
elseif ($type === 'debit') { $where .= " AND t.amount < 0"; }
if ($qSearch !== '') {
    $where .= " AND (t.description LIKE :q OR t.notes LIKE :q)";
    $params[':q'] = '%'.$qSearch.'%';
}

// Total pour pagination
$stCount = $pdo->prepare("SELECT COUNT(*)".$fromJoin.$where);
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sqlTx = "SELECT ".implode(", ", $cols).$fromJoin.$where." ORDER BY date(t.date) DESC, t.id DESC LIMIT :lim OFFSET :off";
$stT = $pdo->prepare($sqlTx);
foreach ($params as $k => $v) $stT->bindValue($k, $v);
$stT->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stT->bindValue(':off', $offset, PDO::PARAM_INT);
$stT->execute();
$tx = $stT->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Conserver filtres dans URLs
function buildQuery(array $in): string {
    $out = [];
    foreach ($in as $k=>$v) {
        if ($v === null) continue;
        if (is_string($v) && $v === '') continue;
        $out[$k] = $v;
    }
    return http_build_query($out);
}
$baseFilters = [
    'account_id'  => $accountId ?: null,
    'category_id' => ($hasCategories && $categoryId) ? $categoryId : null,
    'from'        => $dateFrom ?: null,
    'to'          => $dateTo ?: null,
    'type'        => $type ?: null,
    'q'           => $qSearch !== '' ? $qSearch : null,
    'pp'          => $perPage,
    'p'           => $page
];
$exportUrl = 'export_csv.php?'.buildQuery($baseFilters);

// Lien "Nouvelle transaction"
$newTxParams = ['new' => 1];
if ($accountId) $newTxParams['account_id'] = (int)$accountId;
$newTxUrl = 'transaction_edit.php?' . http_build_query($newTxParams);

// Chaîne de retour pour conserver les filtres au delete
$currentQuery = $_SERVER['QUERY_STRING'] ?? '';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Transactions</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__.'/_head_assets.php'; ?>
<style>
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
.table td, .table th { vertical-align: middle; }
</style>
</head>
<body>
<?php include __DIR__.'/_nav.php'; ?>

<div class="container py-3">

  <?php foreach (App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <!-- Colonne filtres + comptes -->
    <div class="col-lg-4">
      <div class="card shadow-sm mb-3">
        <div class="card-header py-2"><strong>Filtres</strong></div>
        <div class="card-body">
          <form method="get" class="row g-2">
            <div class="col-12">
              <label class="form-label">Compte</label>
              <select name="account_id" class="form-select form-select-sm">
                <option value="">Tous</option>
                <?php foreach ($accounts as $a): ?>
                  <option value="<?= (int)$a['id'] ?>" <?= ($accountId===(int)$a['id'])?'selected':'' ?>>
                    <?= h($a['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <?php if ($hasCategories): ?>
            <div class="col-12">
              <label class="form-label">Catégorie</label>
              <select name="category_id" class="form-select form-select-sm">
                <option value="">Toutes</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= ($categoryId===(int)$c['id'])?'selected':'' ?>>
                    <?= h($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>

            <div class="col-6">
              <label class="form-label">Du</label>
              <input type="date" name="from" value="<?= h($dateFrom ?? '') ?>" class="form-control form-control-sm">
            </div>
            <div class="col-6">
              <label class="form-label">Au</label>
              <input type="date" name="to" value="<?= h($dateTo ?? '') ?>" class="form-control form-control-sm">
            </div>

            <div class="col-6">
              <label class="form-label">Type</label>
              <select name="type" class="form-select form-select-sm">
                <option value="">Tous</option>
                <option value="credit" <?= $type==='credit'?'selected':'' ?>>Crédits</option>
                <option value="debit"  <?= $type==='debit'?'selected':''  ?>>Débits</option>
              </select>
            </div>

            <div class="col-6">
              <label class="form-label">Par page</label>
              <select name="pp" class="form-select form-select-sm">
                <?php foreach ([25,50,100,200] as $pp): ?>
                  <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Recherche</label>
              <input type="text" name="q" value="<?= h($qSearch) ?>" class="form-control form-control-sm" placeholder="Description ou notes">
            </div>

            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary btn-sm">Appliquer</button>
              <a class="btn btn-outline-secondary btn-sm" href="index.php">Réinitialiser</a>
              <a class="btn btn-outline-success btn-sm ms-auto" href="<?= h($exportUrl) ?>">Exporter CSV</a>
            </div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <strong>Comptes</strong>
          <?php if ($accounts): ?>
            <small class="text-muted">Total: <span class="mono"><?= fmt($totalAllAccounts) ?> €</span></small>
          <?php endif; ?>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead class="table-light">
                <tr>
                  <th>Nom</th>
                  <th class="text-end">Solde</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$accounts): ?>
                <tr><td colspan="2" class="text-muted">Aucun compte.</td></tr>
              <?php else: foreach ($accounts as $a): ?>
                <?php $bal = $balances[(int)$a['id']] ?? 0.0; ?>
                <tr>
                  <td><?= h($a['name']) ?></td>
                  <td class="text-end mono"><?= fmt($bal) ?> €</td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
              <?php if ($accounts): ?>
              <tfoot>
                <tr>
                  <th class="text-end">Total</th>
                  <th class="text-end mono"><?= fmt($totalAllAccounts) ?> €</th>
                </tr>
              </tfoot>
              <?php endif; ?>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Colonne transactions -->
    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <strong>Transactions</strong>
          <div class="d-flex align-items-center gap-2">
            <small class="text-muted"><?= $totalRows ?> ligne(s)</small>
            <a class="btn btn-sm btn-primary" href="<?= h($newTxUrl) ?>">Nouvelle transaction</a>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Date</th>
                  <th>Compte</th>
                  <?php if ($hasCategories && $trxHasCat): ?><th>Catégorie</th><?php endif; ?>
                  <th>Description</th>
                  <th class="text-end">Montant</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$tx): ?>
                <tr>
                  <td colspan="<?= ($hasCategories && $trxHasCat)?6:5 ?>" class="text-muted">
                    Aucune transaction.
                  </td>
                </tr>
              <?php else: foreach ($tx as $r): ?>
                <tr>
                  <td class="mono"><?= h(frDate((string)$r['date'])) ?></td>
                  <td><?= h($r['account']) ?></td>
                  <?php if ($hasCategories && $trxHasCat): ?><td><?= h($r['category'] ?? '') ?></td><?php endif; ?>
                  <td><?= h($r['description'] ?? '') ?></td>
                  <td class="text-end mono <?= ((float)$r['amount']<0)?'text-danger':'text-success' ?>">
                    <?= fmt((float)$r['amount']) ?> €
                  </td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-secondary" href="transaction_edit.php?id=<?= (int)$r['id'] ?>">Éditer</a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette transaction ?');">
                      <?= App\Util::csrfInput() ?>
                      <input type="hidden" name="action" value="delete_tx">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="return" value="<?= h($currentQuery) ?>">
                      <button class="btn btn-sm btn-outline-danger">Supprimer</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-footer py-2">
          <div class="d-flex align-items-center justify-content-between">
            <?php
              $q = $baseFilters;
              $q['p'] = max(1, $page-1);
              $prevUrl = 'index.php?'.buildQuery($q);
              $q['p'] = min($totalPages, $page+1);
              $nextUrl = 'index.php?'.buildQuery($q);
            ?>
            <a class="btn btn-sm btn-outline-secondary <?= $page<=1?'disabled':'' ?>" href="<?= h($prevUrl) ?>">« Précédent</a>
            <span class="small text-muted">Page <?= $page ?>/<?= $totalPages ?></span>
            <a class="btn btn-sm btn-outline-secondary <?= $page>=$totalPages?'disabled':'' ?>" href="<?= h($nextUrl) ?>">Suivant »</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>