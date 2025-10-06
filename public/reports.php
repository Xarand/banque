<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\{Util, Database, FinanceRepository};

Util::startSession();
Util::requireAuth();

$db   = new Database();
$repo = new FinanceRepository($db);
$userId = Util::currentUserId();

/* Filtres période */
$dateFrom = isset($_GET['from']) ? trim($_GET['from']) : '';
$dateTo   = isset($_GET['to']) ? trim($_GET['to']) : '';
$valid = fn(string $d)=>$d==='' || preg_match('/^\d{4}-\d{2}-\d{2}$/',$d);
if (!$valid($dateFrom)) $dateFrom='';
if (!$valid($dateTo))   $dateTo='';

$cats     = $repo->getCategoryTotals($userId, $dateFrom ?: null, $dateTo ?: null);
$uncat    = $repo->getUncategorizedTotals($userId, $dateFrom ?: null, $dateTo ?: null);
$accounts = $repo->getAccountTotals($userId, $dateFrom ?: null, $dateTo ?: null);
$monthly  = $repo->getMonthlyFlows($userId, 12, $dateTo ?: null);

/* Préparation pour graphiques */
$maxAbsCat = 0;
foreach ($cats as $c) {
    $abs = abs((float)$c['total']);
    if ($abs>$maxAbsCat) $maxAbsCat=$abs;
}
$maxAbsCat = $maxAbsCat ?: 1;

$maxAbsMonthly = 0;
foreach ($monthly as $m) {
    $abs = max((float)$m['credits'], (float)$m['debits'], abs((float)$m['net']));
    if ($abs>$maxAbsMonthly) $maxAbsMonthly=$abs;
}
$maxAbsMonthly = $maxAbsMonthly ?: 1;

function h($v){ return App\Util::h((string)$v); }

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Rapports</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
<style>
.section-title { font-size:1rem; font-weight:600; margin-top:1.2rem; }
.cat-row-bar { height:6px; border-radius:3px; background:linear-gradient(90deg,#0d6efd,#6610f2); opacity:.25; }
.small-num { font-variant-numeric: tabular-nums; }
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
        <li class="nav-item"><a class="nav-link" href="index.php">Tableau</a></li>
        <li class="nav-item"><a class="nav-link active" href="reports.php">Rapports</a></li>
      </ul>
      <div class="d-flex">
        <a href="logout.php" class="btn btn-sm btn-outline-light">Déconnexion</a>
      </div>
    </div>
  </div>
</nav>

<div class="container pb-5">

  <form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-6 col-md-3">
      <label class="form-label mb-1">Du</label>
      <input type="date" name="from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label mb-1">Au</label>
      <input type="date" name="to" class="form-control form-control-sm" value="<?= h($dateTo) ?>">
    </div>
    <div class="col-12 col-md-3 d-flex gap-2">
      <button class="btn btn-sm btn-primary flex-fill">Filtrer</button>
      <?php if($dateFrom || $dateTo): ?>
        <a class="btn btn-sm btn-outline-secondary" href="reports.php" title="Réinitialiser">✕</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Flux mensuels -->
  <div class="section-title">Flux mensuels (12 derniers mois)</div>
  <div class="table-responsive mb-4">
    <table class="table table-sm table-striped">
      <thead>
        <tr>
          <th>Mois</th>
          <th class="text-end">Crédits</th>
            <th class="text-end">Débits</th>
          <th class="text-end">Net</th>
          <th>Mini Spark</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($monthly as $m): 
          $credits=(float)$m['credits'];
          $debits=(float)$m['debits'];
          $net=(float)$m['net'];
          $scale = $maxAbsMonthly ?: 1;
          $wCredit = $credits/$scale*100;
          $wDebit  = $debits/$scale*100;
        ?>
        <tr>
          <td><?= h($m['ym']) ?></td>
          <td class="text-end text-success small-num"><?= number_format($credits,2,',',' ') ?></td>
          <td class="text-end text-danger small-num"><?= number_format($debits,2,',',' ') ?></td>
          <td class="text-end small-num <?= $net<0?'text-danger':'text-success' ?>"><?= number_format($net,2,',',' ') ?></td>
          <td>
            <div class="d-flex align-items-center" style="gap:4px;">
              <div style="background:#157347;height:6px;width:<?= round($wCredit) ?>px;"></div>
              <div style="background:#b02a37;height:6px;width:<?= round($wDebit) ?>px;"></div>
            </div>
          </td>
        </tr>
        <?php endforeach; if(!$monthly): ?>
          <tr><td colspan="5" class="text-muted">Aucune donnée.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Totaux par catégorie -->
  <div class="section-title">Totaux par catégorie</div>
  <div class="table-responsive mb-4">
    <table class="table table-sm table-striped">
      <thead>
        <tr>
          <th>Catégorie</th>
          <th>Type</th>
          <th class="text-end">Total</th>
          <th class="text-end">Nb</th>
          <th style="width:140px;">Barre</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($cats as $c):
          $tot = (float)$c['total'];
          $ratio = min(100, abs($tot)/$maxAbsCat*100);
        ?>
        <tr>
          <td><?= h($c['name']) ?></td>
          <td><?= $c['type']==='income'?'Revenu':($c['type']==='expense'?'Dépense':'—') ?></td>
          <td class="text-end small-num <?= $tot<0?'text-danger':'text-success' ?>"><?= number_format($tot,2,',',' ') ?></td>
          <td class="text-end small-num"><?= (int)$c['txn_count'] ?></td>
          <td>
            <div class="position-relative" style="height:6px;background:rgba(0,0,0,.08);border-radius:3px;">
              <div style="position:absolute;top:0;left:0;height:6px;border-radius:3px;
                   width:<?= $ratio ?>%;background:<?= $tot>=0?'#157347':'#b02a37' ?>;opacity:.6;"></div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if($uncat['txn_count']>0): 
          $tot=(float)$uncat['total']; $ratio=min(100, abs($tot)/$maxAbsCat*100);
        ?>
        <tr class="table-warning">
          <td>(Sans catégorie)</td>
          <td>—</td>
          <td class="text-end small-num <?= $tot<0?'text-danger':'text-success' ?>"><?= number_format($tot,2,',',' ') ?></td>
          <td class="text-end small-num"><?= (int)$uncat['txn_count'] ?></td>
          <td>
            <div class="position-relative" style="height:6px;background:rgba(0,0,0,.08);border-radius:3px;">
              <div style="position:absolute;top:0;left:0;height:6px;border-radius:3px;
                   width:<?= $ratio ?>%;background:<?= $tot>=0?'#157347':'#b02a37' ?>;opacity:.6;"></div>
            </div>
          </td>
        </tr>
        <?php endif; if(!$cats && !$uncat['txn_count']): ?>
          <tr><td colspan="5" class="text-muted">Aucune donnée.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Totaux par compte -->
  <div class="section-title">Totaux par compte</div>
  <div class="table-responsive mb-5">
    <table class="table table-sm table-striped">
      <thead>
        <tr>
          <th>Compte</th>
          <th class="text-end">Crédits</th>
          <th class="text-end">Débits</th>
          <th class="text-end">Net</th>
          <th class="text-end">Nb</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($accounts as $a):
          $credits=(float)$a['credits'];
          $debits=(float)$a['debits'];
          $net=(float)$a['total'];
        ?>
        <tr>
          <td><?= h($a['name']) ?></td>
          <td class="text-end text-success small-num"><?= number_format($credits,2,',',' ') ?></td>
          <td class="text-end text-danger small-num"><?= number_format($debits,2,',',' ') ?></td>
          <td class="text-end small-num <?= $net<0?'text-danger':'text-success' ?>"><?= number_format($net,2,',',' ') ?></td>
          <td class="text-end small-num"><?= (int)$a['txn_count'] ?></td>
        </tr>
        <?php endforeach; if(!$accounts): ?>
          <tr><td colspan="5" class="text-muted">Aucune donnée.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>