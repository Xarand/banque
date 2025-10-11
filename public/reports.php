<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
use App\{Util, Database};

ini_set('display_errors','1'); error_reporting(E_ALL);
Util::startSession(); Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

// Helpers
function h(string $s): string { return App\Util::h($s); }
function fmt(float $n): string { return number_format($n, 2, ',', ' '); }
function hasTable(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
    $st->execute([':t'=>$table]); return (bool)$st->fetchColumn();
}
function hasCol(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("PRAGMA table_info($table)"); $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (strcasecmp((string)$c['name'],$col)===0) return true;
    return false;
}
function parseDate(?string $s): ?string {
    if (!$s) return null; $s = trim($s);
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~',$s)) return $s;
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~',$s,$m)) return sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[2],(int)$m[1]);
    return null;
}

// Schéma
$hasCategories = hasTable($pdo,'categories');
$trxHasCat     = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','category_id');
$trxHasUser    = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','user_id');
$trxHasDate    = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','date');
$trxHasExcl    = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','exclude_from_ca');
$accHasUser    = hasTable($pdo,'accounts') && hasCol($pdo,'accounts','user_id');
$catHasCounts  = $hasCategories && hasCol($pdo,'categories','counts_in_ca');

// Filtres
$accountId      = isset($_GET['account_id']) ? (int)$_GET['account_id'] : null;
$dateFrom       = parseDate($_GET['from'] ?? $_GET['du'] ?? null);
$dateTo         = parseDate($_GET['to']   ?? $_GET['au'] ?? null);
$onlyCountsInCA = isset($_GET['counts_in_ca']);
$respectExcl    = isset($_GET['respect_excl']) ? (bool)$_GET['respect_excl'] : true;

// Comptes pour filtre
$accounts = [];
try {
    $sqlAcc = "SELECT id, name".($accHasUser?", user_id":"")." FROM accounts";
    $pAcc = [];
    if ($accHasUser) { $sqlAcc .= " WHERE user_id=:u"; $pAcc[':u']=$userId; }
    $sqlAcc .= " ORDER BY name ASC";
    $st = $pdo->prepare($sqlAcc); $st->execute($pAcc);
    $accounts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// Agrégat par catégorie
$rows = [];
if ($hasCategories && $trxHasCat) {
    $params = [];
    $sql = "SELECT
              c.id, c.name, COALESCE(NULLIF(c.type,''),'debit') AS type,
              SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END) AS credit_total,
              SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END) AS debit_total,
              SUM(t.amount) AS net_total,
              COUNT(*) AS n
            FROM transactions t
            JOIN accounts a ON a.id=t.account_id
            JOIN categories c ON c.id=t.category_id
            WHERE 1=1";
    if ($trxHasUser) { $sql .= " AND t.user_id=:u"; $params[':u']=$userId; }
    if ($accHasUser) { $sql .= " AND (a.user_id=:ua OR a.user_id IS NULL)"; $params[':ua']=$userId; }
    if ($accountId)  { $sql .= " AND t.account_id=:acc"; $params[':acc']=$accountId; }
    if ($trxHasDate && $dateFrom) { $sql .= " AND date(t.date) >= date(:df)"; $params[':df']=$dateFrom; }
    if ($trxHasDate && $dateTo)   { $sql .= " AND date(t.date) <= date(:dt)"; $params[':dt']=$dateTo; }
    if ($trxHasExcl && $respectExcl) { $sql .= " AND COALESCE(t.exclude_from_ca,0)=0"; }
    if ($onlyCountsInCA && $catHasCounts) { $sql .= " AND COALESCE(c.counts_in_ca,1)=1"; }
    $sql .= " GROUP BY c.id, c.name, c.type
              ORDER BY net_total DESC, credit_total DESC";
    $st = $pdo->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Data pour chart
$labels = []; $dataCredit=[]; $dataDebitSigned=[]; $dataNet=[];
foreach ($rows as $r) {
    $labels[] = (string)$r['name'];
    $dataCredit[] = round((float)($r['credit_total'] ?? 0), 2);
    // on met les débits en négatif pour empiler visuellement
    $dataDebitSigned[] = -round((float)($r['debit_total'] ?? 0), 2);
    $dataNet[] = round((float)($r['net_total'] ?? 0), 2);
}

// Export CSV
if (isset($_GET['export']) && $_GET['export']==='1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=categories_report.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['Categorie','Type','Credits','Debits','Net','Transactions'], ';', '"');
    foreach ($rows as $r) {
        fputcsv($out, [
            (string)$r['name'],
            ((string)$r['type'] === 'credit' ? 'Crédit' : 'Débit'),
            number_format((float)$r['credit_total'], 2, ',', ' '),
            number_format((float)$r['debit_total'], 2, ',', ' '),
            number_format((float)$r['net_total'],    2, ',', ' '),
            (int)$r['n'],
        ], ';', '"');
    }
    fclose($out); exit;
}

// Conserver les filtres
function buildQuery(array $in): string {
    $out = [];
    foreach ($in as $k=>$v) {
        if ($v === null) continue;
        if (is_string($v) && $v==='') continue;
        $out[$k]=$v;
    }
    return http_build_query($out);
}
$baseFilters = [
    'account_id'  => $accountId ?: null,
    'from'        => $dateFrom ?: null,
    'to'          => $dateTo   ?: null,
    'counts_in_ca'=> $onlyCountsInCA ? 1 : null,
    'respect_excl'=> $respectExcl ? 1 : null,
];
$exportUrl = 'reports.php?'.buildQuery($baseFilters + ['export'=>1]);

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Rapports — Montants par catégorie</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__.'/_head_assets.php'; ?>
<style>.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Courier New",monospace}</style>
</head>
<body>
<?php include __DIR__.'/_nav.php'; ?>

<div class="container py-3">

  <?php foreach (Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>Filtres</strong></div>
    <div class="card-body">
      <?php if (!$hasCategories || !$trxHasCat): ?>
        <div class="alert alert-warning mb-0">
          La visualisation par catégories nécessite la table <code>categories</code> et la colonne
          <code>transactions.category_id</code>. Merci de vérifier votre schéma.
        </div>
      <?php else: ?>
      <form method="get" class="row g-2">
        <div class="col-md-3">
          <label class="form-label">Compte</label>
          <select name="account_id" class="form-select form-select-sm">
            <option value="">Tous</option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= ($accountId===(int)$a['id'])?'selected':'' ?>><?= h($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Du</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?= h($dateFrom ?? '') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Au</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= h($dateTo ?? '') ?>">
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="counts_in_ca" id="counts_in_ca" <?= $onlyCountsInCA?'checked':'' ?>>
            <label class="form-check-label" for="counts_in_ca">Incluse dans le CA uniquement</label>
          </div>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="respect_excl" id="respect_excl" <?= $respectExcl?'checked':'' ?>>
            <label class="form-check-label" for="respect_excl">Respecter “Exclure du CA”</label>
          </div>
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary btn-sm">Appliquer</button>
          <a class="btn btn-outline-secondary btn-sm" href="reports.php">Réinitialiser</a>
          <a class="btn btn-outline-success btn-sm ms-auto" href="<?= h($exportUrl) ?>">Exporter CSV</a>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <strong>Graphique — Montants par catégorie</strong>
      <small class="text-muted">Crédit (barre bleue), Débit (barre rouge négative), Net (ligne grise)</small>
    </div>
    <div class="card-body">
      <canvas id="catChart" height="<?= max(200, count($labels)*24 + 60) ?>"></canvas>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header py-2"><strong>Détails (table)</strong></div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Catégorie</th>
              <th>Type</th>
              <th class="text-end">Crédits</th>
              <th class="text-end">Débits</th>
              <th class="text-end">Net</th>
              <th class="text-end">Transactions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-muted">Aucune donnée pour les filtres sélectionnés.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?= h((string)$r['name']) ?></td>
              <td><?= ((string)$r['type']==='credit') ? 'Crédit' : 'Débit' ?></td>
              <td class="text-end mono text-success"><?= fmt((float)($r['credit_total'] ?? 0)) ?> €</td>
              <td class="text-end mono text-danger"><?= fmt((float)($r['debit_total'] ?? 0)) ?> €</td>
              <td class="text-end mono"><strong><?= fmt((float)($r['net_total'] ?? 0)) ?> €</strong></td>
              <td class="text-end"><?= (int)($r['n'] ?? 0) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  if (!window.Chart) return;
  const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
  const credits = <?= json_encode($dataCredit, JSON_UNESCAPED_UNICODE) ?>;
  const debitsN = <?= json_encode($dataDebitSigned, JSON_UNESCAPED_UNICODE) ?>;
  const nets    = <?= json_encode($dataNet, JSON_UNESCAPED_UNICODE) ?>;

  const colors = (window.AppThemeChart && window.AppThemeChart.colors) ? window.AppThemeChart.colors : {
    credit: '#0D6EFD', debit: '#dc3545', net: '#6c757d', fg: '#212529', grid: '#DEE2E6'
  };

  const ctx = document.getElementById('catChart').getContext('2d');
  const chart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          type: 'bar',
          label: 'Crédits',
          data: credits,
          backgroundColor: colors.credit,
          borderColor: colors.credit,
          borderWidth: 1,
          stack: 'stack1'
        },
        {
          type: 'bar',
          label: 'Débits',
          data: debitsN, // valeurs négatives
          backgroundColor: colors.debit,
          borderColor: colors.debit,
          borderWidth: 1,
          stack: 'stack1'
        },
        {
          type: 'line',
          label: 'Net',
          data: nets,
          borderColor: colors.net,
          backgroundColor: colors.net,
          borderWidth: 2,
          pointRadius: 2,
          yAxisID: 'y'
        }
      ]
    },
    options: {
      indexAxis: 'y', // barres horizontales
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: {
          stacked: true,
          ticks: { callback: (v) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(v) + ' €' }
        },
        y: { stacked: true }
      },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          callbacks: {
            label: function(ctx){
              const v = ctx.raw ?? 0;
              const label = ctx.dataset.label || '';
              return label + ': ' + new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v) + ' €';
            }
          }
        }
      }
    }
  });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>