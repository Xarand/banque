<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__.'/../config/bootstrap.php';

use App\{Util, Database};

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

/* Helpers */
function h(string $s): string { return App\Util::h($s); }
function fmt(float $n, int $dec = 2): string { return number_format($n, $dec, ',', ' '); }
function hasTable(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
    $st->execute([':t'=>$table]); return (bool)$st->fetchColumn();
}
function hasCol(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("PRAGMA table_info($table)"); $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (strcasecmp((string)$c['name'], $col) === 0) return true;
    return false;
}
function monthNameFR(int $m): string {
    $n=[1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
    return $n[$m] ?? (string)$m;
}
function lastDayOfMonth(string $ymd): string { $ts=strtotime($ymd); return date('Y-m-t', $ts?:time()); }
function lastDayOfNextMonthFromEnd(string $endYmd): string { $ts=strtotime($endYmd.' +1 month'); return date('Y-m-t', $ts?:time()); }
function barColor(float $ratio): string { if ($ratio<0.80) return 'bg-success'; if ($ratio<1.00) return 'bg-warning'; return 'bg-danger'; }
function barWidthPct(float $value, float $ceil): float { if ($ceil<=0) return 0.0; return max(0,min(100, ($value/$ceil)*100)); }

/* Référentiel activités */
$activities = [];
$cfgPath = __DIR__ . '/../config/micro_activities.php';
if (is_file($cfgPath)) $activities = require $cfgPath;

/* Micro: s’assure des colonnes minimales et charge la micro de l’utilisateur */
if (!hasTable($pdo, 'micro_enterprises')) {
    include __DIR__.'/_nav.php';
    echo '<div class="container py-3"><div class="alert alert-warning">Aucune micro‑entreprise détectée.</div></div>'; exit;
}
foreach ([
    ['user_id','INTEGER'],['activity_code','TEXT'],['created_at','TEXT'],
    ['declaration_period','TEXT'],['versement_liberatoire','INTEGER'],
    ['ca_ceiling','REAL'],['vat_ceiling','REAL'],['vat_ceiling_major','REAL'],
    ['social_contrib_rate','REAL'],['income_tax_rate','REAL'],['cfp_rate','REAL'],['cma_rate','REAL'],
] as [$c,$t]) if (!hasCol($pdo,'micro_enterprises',$c)) $pdo->exec("ALTER TABLE micro_enterprises ADD COLUMN $c $t");

$microHasUser = hasCol($pdo,'micro_enterprises','user_id');
$sqlMicro="SELECT * FROM micro_enterprises"; $p=[];
if ($microHasUser){ $sqlMicro.=" WHERE user_id=:u"; $p[':u']=$userId; }
$sqlMicro.=" LIMIT 1";
$st=$pdo->prepare($sqlMicro); $st->execute($p); $micro=$st->fetch(PDO::FETCH_ASSOC);
if (!$micro) { include __DIR__.'/_nav.php'; echo '<div class="container py-3"><div class="alert alert-info">Créez un compte de type “Micro” dans l’onglet Comptes.</div></div>'; exit; }

$mid=(int)$micro['id'];
$code=(string)($micro['activity_code']??'');
$def=$activities[$code]??null;

$ceil_ca      = isset($micro['ca_ceiling'])        ? (float)$micro['ca_ceiling']        : (float)($def['ceilings']['ca']        ?? 0);
$ceil_vat     = isset($micro['vat_ceiling'])       ? (float)$micro['vat_ceiling']       : (float)($def['ceilings']['vat']       ?? 0);
$ceil_vat_maj = isset($micro['vat_ceiling_major']) ? (float)$micro['vat_ceiling_major'] : (float)($def['ceilings']['vat_major'] ?? 0);

$rate_social  = isset($micro['social_contrib_rate']) ? (float)$micro['social_contrib_rate'] : (float)($def['rates']['social'] ?? 0);
$vl           = (int)($micro['versement_liberatoire'] ?? 0);

// Impôt libératoire: si activé (vl=1), prend micro.income_tax_rate si >0, sinon applique le taux de l’activité et sauvegarde.
$rate_tax = 0.0;
if ($vl) {
    $rateInDb = isset($micro['income_tax_rate']) ? (float)$micro['income_tax_rate'] : 0.0;
    if ($rateInDb > 0) {
        $rate_tax = $rateInDb;
    } else {
        $fallback = (float)($def['rates']['income_tax'] ?? 0.0);
        $rate_tax = $fallback;
        if ($fallback > 0) {
            $upd=$pdo->prepare("UPDATE micro_enterprises SET income_tax_rate = :ri WHERE id = :id");
            $upd->execute([':ri'=>$fallback, ':id'=>$mid]);
            $micro['income_tax_rate']=$fallback;
        }
    }
}
$rate_cfp = isset($micro['cfp_rate']) ? (float)$micro['cfp_rate'] : (float)($def['rates']['cfp'] ?? 0);
$rate_cma = isset($micro['cma_rate']) ? (float)$micro['cma_rate'] : (float)($def['rates']['cma'] ?? 0);
$activityLabel = $def['label'] ?? ($code!==''?('Activité « '.$code.' »'):'—');
$declPeriod = (string)($micro['declaration_period'] ?? 'quarterly'); // monthly|quarterly

/* CA année en cours pour barres */
$yearNow = (int)date('Y'); $fromYearStart=date('Y-01-01'); $today=date('Y-m-d');
$hasTrx=hasTable($pdo,'transactions'); $hasAcc=hasTable($pdo,'accounts');
$trxHasDate=$hasTrx && hasCol($pdo,'transactions','date');
$trxHasAmt =$hasTrx && hasCol($pdo,'transactions','amount');
$trxHasUser=$hasTrx && hasCol($pdo,'transactions','user_id');
$trxHasExCa=$hasTrx && hasCol($pdo,'transactions','exclude_from_ca');
$accHasMicro=$hasAcc && hasCol($pdo,'accounts','micro_enterprise_id');
$accHasUser =$hasAcc && hasCol($pdo,'accounts','user_id');

$caYearToDate=0.0;
if ($hasTrx && $hasAcc && $trxHasAmt && $accHasMicro) {
    $sql="SELECT ROUND(COALESCE(SUM(t.amount),0),2) FROM transactions t JOIN accounts a ON a.id=t.account_id
          WHERE a.micro_enterprise_id=:mid AND t.amount>0";
    $bind=[':mid'=>$mid];
    if ($trxHasDate){ $sql.=" AND date(t.date)>=date(:d1) AND date(t.date)<=date(:d2)"; $bind[':d1']=$fromYearStart; $bind[':d2']=$today; }
    if ($trxHasUser){ $sql.=" AND t.user_id=:u"; $bind[':u']=$userId; }
    if ($accHasUser){ $sql.=" AND (a.user_id=:au OR a.user_id IS NULL)"; $bind[':au']=$userId; }
    if ($trxHasExCa){ $sql.=" AND COALESCE(t.exclude_from_ca,0)=0"; }
    $s=$pdo->prepare($sql); $s->execute($bind); $caYearToDate=(float)$s->fetchColumn();
}

// Année affichée + périodes détaillées
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year<2000 || $year>2100) $year=(int)date('Y');

$periodRows=[]; $yearTotalCA=0.0; $yearTotalDue=0.0;
$sumRate = $rate_social + $rate_tax + $rate_cfp + $rate_cma;

if ($declPeriod === 'monthly') {
    for ($m=1; $m<=12; $m++){
        $from=sprintf('%04d-%02d-01',$year,$m); $to=lastDayOfMonth($from); $due=lastDayOfNextMonthFromEnd($to);
        $ca=0.0;
        if ($hasTrx && $hasAcc && $trxHasAmt && $accHasMicro && $trxHasDate) {
            $sql="SELECT ROUND(COALESCE(SUM(t.amount),0),2) FROM transactions t JOIN accounts a ON a.id=t.account_id
                  WHERE a.micro_enterprise_id=:mid AND t.amount>0
                  AND date(t.date)>=date(:d1) AND date(t.date)<=date(:d2)";
            $bind=[':mid'=>$mid, ':d1'=>$from, ':d2'=>$to];
            if ($trxHasUser){ $sql.=" AND t.user_id=:u"; $bind[':u']=$userId; }
            if ($accHasUser){ $sql.=" AND (a.user_id=:au OR a.user_id IS NULL)"; $bind[':au']=$userId; }
            if ($trxHasExCa){ $sql.=" AND COALESCE(t.exclude_from_ca,0)=0"; }
            $s=$pdo->prepare($sql); $s->execute($bind); $ca=(float)$s->fetchColumn();
        }
        $totalDue=round($ca * $sumRate, 2);
        $periodRows[]=['label'=>monthNameFR($m).' '.$year,'from'=>$from,'to'=>$to,'due'=>$due,'ca'=>$ca,'total'=>$totalDue];
        $yearTotalCA+=$ca; $yearTotalDue+=$totalDue;
    }
} else {
    $quarters=[['T1',"$year-01-01","$year-03-31"],['T2',"$year-04-01","$year-06-30"],['T3',"$year-07-01","$year-09-30"],['T4',"$year-10-01","$year-12-31"]];
    foreach($quarters as [$ql,$from,$to]){
        $due=lastDayOfNextMonthFromEnd($to);
        $ca=0.0;
        if ($hasTrx && $hasAcc && $trxHasAmt && $accHasMicro && $trxHasDate) {
            $sql="SELECT ROUND(COALESCE(SUM(t.amount),0),2) FROM transactions t JOIN accounts a ON a.id=t.account_id
                  WHERE a.micro_enterprise_id=:mid AND t.amount>0
                  AND date(t.date)>=date(:d1) AND date(t.date)<=date(:d2)";
            $bind=[':mid'=>$mid, ':d1'=>$from, ':d2'=>$to];
            if ($trxHasUser){ $sql.=" AND t.user_id=:u"; $bind[':u']=$userId; }
            if ($accHasUser){ $sql.=" AND (a.user_id=:au OR a.user_id IS NULL)"; $bind[':au']=$userId; }
            if ($trxHasExCa){ $sql.=" AND COALESCE(t.exclude_from_ca,0)=0"; }
            $s=$pdo->prepare($sql); $s->execute($bind); $ca=(float)$s->fetchColumn();
        }
        $totalDue=round($ca * $sumRate, 2);
        $periodRows[]=['label'=>$ql.' '.$year,'from'=>$from,'to'=>$to,'due'=>$due,'ca'=>$ca,'total'=>$totalDue];
        $yearTotalCA+=$ca; $yearTotalDue+=$totalDue;
    }
}

/* Curseurs (progress) et états */
$pctPlafCA   = $ceil_ca     > 0 ? min(100, ($caYearToDate / $ceil_ca)     * 100) : 0;
$pctVat      = $ceil_vat    > 0 ? min(100, ($caYearToDate / $ceil_vat)    * 100) : 0;
$pctVatMajor = $ceil_vat_maj> 0 ? min(100, ($caYearToDate / $ceil_vat_maj)* 100) : 0;

$warnPlafCA   = ($ceil_ca      > 0 && $caYearToDate >= $ceil_ca);
$warnVat      = ($ceil_vat     > 0 && $caYearToDate >= $ceil_vat);
$warnVatMajor = ($ceil_vat_maj > 0 && $caYearToDate >= $ceil_vat_maj);

include __DIR__.'/_nav.php';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Cotisations</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__.'/_head_assets.php'; ?>
<style>.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Courier New",monospace}</style>
</head>
<body>
<div class="container py-3">

  <?php foreach (Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <!-- Info + barres -->
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Micro‑entreprise</strong></div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm mb-3">
              <tbody>
                <tr><th style="width:50%">Activité</th><td><?= h($activityLabel) ?></td></tr>
                <tr><th>Déclaration de CA</th><td><?= $declPeriod==='monthly'?'Mensuelle':'Trimestrielle' ?></td></tr>
                <tr><th>Impôt libératoire</th><td><?= $vl ? 'Oui' : 'Non' ?></td></tr>
                <tr><th>CA <?= (int)date('Y') ?></th><td class="mono"><?= fmt($caYearToDate,2) ?> €</td></tr>
              </tbody>
            </table>
          </div>

          <div class="mb-3">
            <div class="d-flex justify-content-between"><span>CA / Plafond activité</span><span class="mono"><?= fmt($caYearToDate,0) ?> € / <?= fmt($ceil_ca,0) ?> €</span></div>
            <?php $pct=barWidthPct($caYearToDate,$ceil_ca); $ratio=$ceil_ca>0 ? $caYearToDate/$ceil_ca : 0; ?>
            <div class="progress"><div class="progress-bar <?= barColor($ratio) ?>" style="width: <?= number_format($pct,1,'.','') ?>%"></div></div>
            <?php if ($warnPlafCA): ?><div class="text-danger small mt-1">Plafond atteint/dépassé.</div><?php endif; ?>
          </div>
          <div class="mb-3">
            <div class="d-flex justify-content-between"><span>CA / Seuil TVA</span><span class="mono"><?= fmt($caYearToDate,0) ?> € / <?= fmt($ceil_vat,0) ?> €</span></div>
            <?php $pct=barWidthPct($caYearToDate,$ceil_vat); $ratio=$ceil_vat>0 ? $caYearToDate/$ceil_vat : 0; ?>
            <div class="progress"><div class="progress-bar <?= barColor($ratio) ?>" style="width: <?= number_format($pct,1,'.','') ?>%"></div></div>
            <?php if ($warnVat): ?><div class="text-warning small mt-1">Seuil normal de TVA atteint/dépassé.</div><?php endif; ?>
          </div>
          <div>
            <div class="d-flex justify-content-between"><span>CA / Seuil TVA majoré</span><span class="mono"><?= fmt($caYearToDate,0) ?> € / <?= fmt($ceil_vat_maj,0) ?> €</span></div>
            <?php $pct=barWidthPct($caYearToDate,$ceil_vat_maj); $ratio=$ceil_vat_maj>0 ? $caYearToDate/$ceil_vat_maj : 0; ?>
            <div class="progress"><div class="progress-bar <?= barColor($ratio) ?>" style="width: <?= number_format($pct,1,'.','') ?>%"></div></div>
            <?php if ($warnVatMajor): ?><div class="text-danger small mt-1">Seuil majoré de TVA atteint/dépassé.</div><?php endif; ?>
          </div>

          <?php if (($ceil_ca+$ceil_vat+$ceil_vat_maj)<=0): ?>
            <div class="alert alert-warning mt-3 mb-0">Plafonds non définis pour cette activité.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Cotisations détaillées -->
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <strong>Cotisations à payer — <?= $declPeriod==='monthly'?'Mensuel':'Trimestriel' ?></strong>
          <div class="d-flex align-items-center gap-2">
            <?php $prevY=$year-1; $nextY=$year+1; ?>
            <a class="btn btn-sm btn-outline-secondary" href="micro_index.php?year=<?= $prevY ?>">&laquo; <?= $prevY ?></a>
            <span class="fw-bold"><?= $year ?></span>
            <?php if ($nextY <= (int)date('Y')): ?>
              <a class="btn btn-sm btn-outline-secondary" href="micro_index.php?year=<?= $nextY ?>"><?= $nextY ?> &raquo;</a>
            <?php endif; ?>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Période</th>
                  <th class="text-center">Dates</th>
                  <th class="text-center">Échéance</th>
                  <th class="text-end">CA</th>
                  <th class="text-end">Total dû</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$periodRows): ?>
                  <tr><td colspan="5" class="text-muted">Aucune période.</td></tr>
                <?php else: foreach ($periodRows as $row): ?>
                  <tr>
                    <td><?= h($row['label']) ?></td>
                    <td class="text-center"><?= h(date('d/m/Y', strtotime($row['from']))) ?> → <?= h(date('d/m/Y', strtotime($row['to']))) ?></td>
                    <td class="text-center"><?= h(date('d/m/Y', strtotime($row['due']))) ?></td>
                    <td class="text-end mono"><?= fmt((float)$row['ca'],2) ?> €</td>
                    <td class="text-end mono"><strong><?= fmt((float)$row['total'],2) ?> €</strong></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="3" class="text-end">Total <?= $year ?></th>
                  <th class="text-end mono"><?= fmt($yearTotalCA,2) ?> €</th>
                  <th class="text-end mono"><?= fmt($yearTotalDue,2) ?> €</th>
                </tr>
              </tfoot>
            </table>
          </div>
          <div class="px-3 py-2">
            <small class="text-muted">Échéance: dernier jour du mois suivant la fin de période.</small>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>