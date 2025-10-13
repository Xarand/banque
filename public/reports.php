<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/bootstrap.php';
use App\{Util, Database};

Util::startSession(); Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

// Helpers
function h(string $s): string { return App\Util::h($s); }
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
    if (!$s) return null; $s=trim($s);
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~',$s)) return $s;
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~',$s,$m)) return sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[2],(int)$m[1]);
    return null;
}
function buildQuery(array $in): string {
    $out=[]; foreach ($in as $k=>$v){ if ($v===null) continue; if (is_string($v)&&$v==='') continue; $out[$k]=$v; }
    return http_build_query($out);
}

// Schéma
$hasCategories = hasTable($pdo,'categories');
$trxHasCat     = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','category_id');
$trxHasUser    = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','user_id');
$trxHasDate    = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','date');
$accHasUser    = hasTable($pdo,'accounts')     && hasCol($pdo,'accounts','user_id');
$catHasType    = $hasCategories && hasCol($pdo,'categories','type');

// Filtres
$accountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : null;
$dateFrom  = parseDate($_GET['from'] ?? null);
$dateTo    = parseDate($_GET['to']   ?? null);
$today = date('Y-m-d');
if (!$dateTo)   $dateTo   = $today;
if (!$dateFrom) $dateFrom = date('Y-m-d', strtotime('-1 month'));

// Raccourcis
function rangeUrl(string $label, string $from, string $to, array $base): array { $q=$base; $q['from']=$from; $q['to']=$to; return ['label'=>$label,'url'=>'reports.php?'.buildQuery($q)]; }
$baseKeep = ['account_id'=>$accountId ?: null];
$oneMFrom = date('Y-m-d', strtotime('-1 month +1 day', strtotime($dateTo)));
$threeM   = date('Y-m-d', strtotime('-3 month +1 day', strtotime($dateTo)));
$sixM     = date('Y-m-d', strtotime('-6 month +1 day', strtotime($dateTo)));
$oneY     = date('Y-m-d', strtotime('-1 year +1 day',  strtotime($dateTo)));
$quickRanges = [
    rangeUrl('1 mois',  $oneMFrom, $dateTo, $baseKeep),
    rangeUrl('3 mois',  $threeM,   $dateTo, $baseKeep),
    rangeUrl('6 mois',  $sixM,     $dateTo, $baseKeep),
    rangeUrl('1 an',    $oneY,     $dateTo, $baseKeep),
];

// Comptes
$accounts=[];
try{
    $sqlAcc="SELECT id, name".($accHasUser?", user_id":"")." FROM accounts";
    $p=[]; if ($accHasUser){ $sqlAcc.=" WHERE user_id=:u"; $p[':u']=$userId; }
    $sqlAcc.=" ORDER BY name ASC"; $st=$pdo->prepare($sqlAcc); $st->execute($p);
    $accounts=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}catch(Throwable $e){}

/* Camembert: Crédits par catégorie (exclut explicitement les catégories de type Débit) */
$pieLabels=[]; $pieValues=[];
if ($trxHasDate) {
    $joinCat = ($hasCategories && $trxHasCat);
    $params=[];
    $sql = "SELECT ".
           ($joinCat ? "COALESCE(c.name,'Non catégorisé')" : "'Crédits'")." AS name, ".
           "SUM(CASE WHEN t.amount>0 THEN t.amount ELSE 0 END) AS credit_total
           FROM transactions t
           JOIN accounts a ON a.id=t.account_id ".
           ($joinCat ? "LEFT JOIN categories c ON c.id=t.category_id " : "").
           "WHERE 1=1";
    if ($trxHasUser){ $sql.=" AND t.user_id=:u"; $params[':u']=$userId; }
    if ($accHasUser){ $sql.=" AND (a.user_id=:ua OR a.user_id IS NULL)"; $params[':ua']=$userId; }
    if ($accountId){  $sql.=" AND t.account_id=:acc"; $params[':acc']=$accountId; }
    $sql.=" AND date(t.date)>=date(:df) AND date(t.date)<=date(:dt)"; $params[':df']=$dateFrom; $params[':dt']=$dateTo;
    // filtre: uniquement catégories marquées "credit"; on exclut les débits du camembert
    if ($joinCat && $catHasType) { $sql .= " AND LOWER(COALESCE(c.type,'')) = 'credit'"; }
    if ($joinCat) { $sql.=" GROUP BY c.id, c.name HAVING credit_total>0"; }
    $sql.=" ORDER BY credit_total DESC";
    $st=$pdo->prepare($sql); $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r){
        $pieLabels[] = (string)$r['name'];
        $pieValues[] = round((float)($r['credit_total'] ?? 0), 2);
    }
}

/* Barres horizontales: Dépenses par catégorie (on a déjà exclu les catégories type 'credit' dans réponse précédente) */
$barLabels=[]; $barValues=[];
if ($trxHasDate) {
    $joinCat = ($hasCategories && $trxHasCat);
    $params=[];
    $sql = "SELECT ".
           ($joinCat ? "COALESCE(c.name,'Non catégorisé')" : "'Dépenses'")." AS name, ".
           "SUM(CASE WHEN t.amount<0 THEN -t.amount ELSE 0 END) AS debit_total
           FROM transactions t
           JOIN accounts a ON a.id=t.account_id ".
           ($joinCat ? "LEFT JOIN categories c ON c.id=t.category_id " : "").
           "WHERE 1=1";
    if ($trxHasUser){ $sql.=" AND t.user_id=:u"; $params[':u']=$userId; }
    if ($accHasUser){ $sql.=" AND (a.user_id=:ua OR a.user_id IS NULL)"; $params[':ua']=$userId; }
    if ($accountId){  $sql.=" AND t.account_id=:acc"; $params[':acc']=$accountId; }
    $sql.=" AND date(t.date)>=date(:df) AND date(t.date)<=date(:dt)"; $params[':df']=$dateFrom; $params[':dt']=$dateTo;
    if ($joinCat && $catHasType) { $sql .= " AND (LOWER(COALESCE(c.type,'')) <> 'credit')"; }
    if ($joinCat) $sql.=" GROUP BY c.id, c.name";
    $sql.=" ORDER BY debit_total DESC";
    $st=$pdo->prepare($sql); $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r){
        $barLabels[] = (string)$r['name'];
        $barValues[] = round((float)($r['debit_total'] ?? 0), 2);
    }
}

// Exports
if (isset($_GET['export']) && $_GET['export']==='pie') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=credits_par_categorie.csv');
    $out=fopen('php://output','w');
    fputcsv($out,['Categorie','Credits'],';','"');
    for($i=0;$i<count($pieLabels);$i++) fputcsv($out,[$pieLabels[$i],number_format((float)$pieValues[$i],2,',',' ')],';','"');
    fclose($out); exit;
}
if (isset($_GET['export']) && $_GET['export']==='expenses_by_category_bars') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=depenses_par_categorie.csv');
    $out=fopen('php://output','w');
    fputcsv($out,['Categorie','Depenses'],';','"');
    for($i=0;$i<count($barLabels);$i++) fputcsv($out,[$barLabels[$i],number_format((float)$barValues[$i],2,',',' ')],';','"');
    fclose($out); exit;
}

$baseFilters = ['account_id'=>$accountId ?: null,'from'=>$dateFrom,'to'=>$dateTo];
$exportPieUrl  = 'reports.php?'.buildQuery($baseFilters + ['export'=>'pie']);
$exportBarsUrl = 'reports.php?'.buildQuery($baseFilters + ['export'=>'expenses_by_category_bars']);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Rapports</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__.'/_head_assets.php'; ?>
<style>.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Courier New",monospace}</style>
</head>
<body>
<?php include __DIR__.'/_nav.php'; ?>

<div class="container py-3">
  <div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <strong>Filtres</strong>
      <div class="d-flex gap-2">
        <?php foreach ($quickRanges as $q): ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= h($q['url']) ?>"><?= h($q['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card-body">
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
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary btn-sm">Appliquer</button>
          <a class="btn btn-outline-secondary btn-sm" href="reports.php">Réinitialiser</a>
          <a class="btn btn-outline-success btn-sm ms-auto" href="<?= h($exportPieUrl) ?>">Export Crédits</a>
          <a class="btn btn-outline-success btn-sm" href="<?= h($exportBarsUrl) ?>">Export Dépenses</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <strong>Crédits par catégorie</strong>
      <small class="text-muted">Période: <?= h($dateFrom) ?> → <?= h($dateTo) ?></small>
    </div>
    <div class="card-body">
      <canvas id="pieCredits" height="220"></canvas>
      <?php if (!$pieLabels): ?><div class="text-muted small mt-2">Aucune entrée pour les filtres.</div><?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <strong>Dépenses par catégorie</strong>
      <small class="text-muted">Période: <?= h($dateFrom) ?> → <?= h($dateTo) ?></small>
    </div>
    <div class="card-body">
      <canvas id="barExpenses" height="<?= max(220, count($barLabels)*28 + 40) ?>"></canvas>
      <?php if (!$barLabels): ?><div class="text-muted small mt-2">Aucune dépense pour les filtres.</div><?php endif; ?>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  if (!window.Chart) return;
  const theme = (window.AppThemeChart && window.AppThemeChart.colors) ? window.AppThemeChart.colors : {
    credit: '#0D6EFD', debit: '#dc3545', net: '#6c757d', fg: '#212529', grid: '#DEE2E6'
  };
  const pieLabels = <?= json_encode($pieLabels, JSON_UNESCAPED_UNICODE) ?>;
  const pieValues = <?= json_encode($pieValues, JSON_UNESCAPED_UNICODE) ?>;
  const barLabels = <?= json_encode($barLabels, JSON_UNESCAPED_UNICODE) ?>;
  const barValues = <?= json_encode($barValues, JSON_UNESCAPED_UNICODE) ?>;

  function genPalette(n, baseHue){ const arr=[]; for(let i=0;i<n;i++){ const h=(baseHue+(i*360/n))%360; arr.push(`hsl(${h} 70% 50%)`);} return arr; }

  if (pieLabels.length){
    const colors = genPalette(pieLabels.length, 210);
    new Chart(document.getElementById('pieCredits').getContext('2d'), {
      type:'pie',
      data:{ labels: pieLabels, datasets:[{ data: pieValues, backgroundColor: colors, borderColor:'#fff', borderWidth:1 }]},
      options:{
        maintainAspectRatio:false,
        plugins:{ legend:{ position:'bottom', labels:{ color: theme.fg } },
          tooltip:{ callbacks:{ label:(ctx)=>{ const v=ctx.raw??0; const s=(pieValues.reduce((a,b)=>a+b,0)||1); const p=100*v/s; return `${ctx.label}: `+new Intl.NumberFormat('fr-FR',{minimumFractionDigits:2}).format(v)+` € (${p.toFixed(1)} %)`; }}}}
      }
    });
  }

  if (barLabels.length){
    const colors = genPalette(barLabels.length, 355).map(c=>c.replace('70% 50%','70% 45%'));
    new Chart(document.getElementById('barExpenses').getContext('2d'), {
      type:'bar',
      data:{ labels: barLabels, datasets:[{ label:'Dépenses', data: barValues, backgroundColor: colors, borderColor: colors, borderWidth:1 }]},
      options:{
        indexAxis:'y', maintainAspectRatio:false, responsive:true,
        scales:{ x:{ ticks:{ callback:(v)=> new Intl.NumberFormat('fr-FR').format(v)+' €' }, grid:{ color:'rgba(0,0,0,.08)'}},
                 y:{ grid:{ display:false }, ticks:{ color: theme.fg }}},
        plugins:{ legend:{ display:false },
          tooltip:{ callbacks:{ label:(ctx)=> new Intl.NumberFormat('fr-FR',{minimumFractionDigits:2}).format(ctx.raw??0)+' €' }}}
      }
    });
  }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>