<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database};

ini_set('display_errors','1'); // à désactiver en prod
error_reporting(E_ALL);

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

/* Helpers */
function h(string $s): string { return App\Util::h($s); }
function fmt(float $n): string { return number_format($n, 2, ',', ' '); }
function hasTable(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
    $st->execute([':t'=>$table]); return (bool)$st->fetchColumn();
}
function hasCol(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("PRAGMA table_info($table)");
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (strcasecmp((string)$c['name'], $col) === 0) return true;
    return false;
}
function parseDate(?string $s): ?string {
    if (!$s) return null;
    $s = trim($s);
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $s)) return $s;
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $s, $m)) return sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[2],(int)$m[1]);
    return null;
}

/* Schéma */
$hasAccounts   = hasTable($pdo,'accounts');
$hasTrx        = hasTable($pdo,'transactions');
$hasMicroTable = hasTable($pdo,'micro_enterprises');

$accHasUser  = $hasAccounts   && hasCol($pdo,'accounts','user_id');
$accHasMicro = $hasAccounts   && hasCol($pdo,'accounts','micro_enterprise_id');
$accHasCAt   = $hasAccounts   && hasCol($pdo,'accounts','created_at');
$trxHasUser  = $hasTrx        && hasCol($pdo,'transactions','user_id');
$trxHasDate  = $hasTrx        && hasCol($pdo,'transactions','date');
$trxHasExcl  = $hasTrx        && hasCol($pdo,'transactions','exclude_from_ca');
$trxHasAccId = $hasTrx        && hasCol($pdo,'transactions','account_id');
$trxHasAmt   = $hasTrx        && hasCol($pdo,'transactions','amount');

/* Récupère la micro de l’utilisateur (si existe) */
$micro = null;
if ($hasMicroTable) {
    $st = $pdo->prepare("SELECT * FROM micro_enterprises WHERE user_id = :u LIMIT 1");
    $st->execute([':u'=>$userId]);
    $micro = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* Filtres période */
$defaultFrom = date('Y').'-01-01';
$defaultTo   = date('Y-m-d');
$dateFrom = parseDate($_GET['from'] ?? $_GET['du'] ?? $defaultFrom) ?: $defaultFrom;
$dateTo   = parseDate($_GET['to']   ?? $_GET['au'] ?? $defaultTo)   ?: $defaultTo;

/* Comptes rattachés à la micro */
$microAccountIds = [];
if ($hasAccounts) {
    $sql = "SELECT id FROM accounts WHERE 1=1";
    $bind = [];
    if ($accHasUser) { $sql .= " AND user_id = :u"; $bind[':u'] = $userId; }
    if ($accHasMicro) {
        if ($micro && isset($micro['id'])) {
            $sql .= " AND micro_enterprise_id = :mid";
            $bind[':mid'] = (int)$micro['id'];
        } else {
            // Si micro pas encore créée mais colonne existe, pas de compte micro => liste vide
            $sql .= " AND 1=0";
        }
    }
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    $microAccountIds = array_map(fn($r)=> (int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/* Calculs CA et charges */
$caPeriod = 0.0;
$caYtd    = 0.0;
$byMonth  = [];

if ($hasTrx && $trxHasAccId && $trxHasAmt) {
    // CA période (somme des montants positifs)
    $params = [];
    $sql = "SELECT COALESCE(SUM(t.amount),0) FROM transactions t";
    if ($hasAccounts) $sql .= " JOIN accounts a ON a.id=t.account_id";
    $sql .= " WHERE t.amount > 0";
    if ($trxHasUser) { $sql .= " AND t.user_id = :u"; $params[':u'] = $userId; }
    if ($trxHasDate) { $sql .= " AND date(t.date) >= date(:df) AND date(t.date) <= date(:dt)"; $params[':df']=$dateFrom; $params[':dt']=$dateTo; }
    if ($trxHasExcl) { $sql .= " AND COALESCE(t.exclude_from_ca,0)=0"; }
    if ($microAccountIds) {
        $in = implode(',', array_fill(0, count($microAccountIds), '?'));
        $sql .= " AND t.account_id IN ($in)";
        $params = array_merge($params, $microAccountIds);
    } else {
        // Pas de compte micro → CA logiquement 0
        $sql .= " AND 1=0";
    }
    $st = $pdo->prepare($sql);
    // Liaisons nommées + positionnelles
    foreach ($params as $k=>$v) { if (is_string($k)) $st->bindValue($k,$v); }
    $pos = 1; foreach ($params as $k=>$v) { if (is_int($k)) $st->bindValue($pos++,$v, PDO::PARAM_INT); }
    $st->execute();
    $caPeriod = (float)$st->fetchColumn();

    // CA YTD (du 1er janvier au jour)
    if ($trxHasDate) {
        $params = [];
        $sql = "SELECT COALESCE(SUM(t.amount),0) FROM transactions t";
        if ($hasAccounts) $sql .= " JOIN accounts a ON a.id=t.account_id";
        $sql .= " WHERE t.amount > 0";
        if ($trxHasUser) { $sql .= " AND t.user_id = :u"; $params[':u'] = $userId; }
        $sql .= " AND date(t.date) >= date(:d1) AND date(t.date) <= date(:d2)";
        $params[':d1'] = date('Y').'-01-01';
        $params[':d2'] = date('Y-m-d');
        if ($trxHasExcl) { $sql .= " AND COALESCE(t.exclude_from_ca,0)=0"; }
        if ($microAccountIds) {
            $in = implode(',', array_fill(0, count($microAccountIds), '?'));
            $sql .= " AND t.account_id IN ($in)";
            $params = array_merge($params, $microAccountIds);
        } else {
            $sql .= " AND 1=0";
        }
        $st = $pdo->prepare($sql);
        foreach ($params as $k=>$v) { if (is_string($k)) $st->bindValue($k,$v); }
        $pos = 1; foreach ($params as $k=>$v) { if (is_int($k)) $st->bindValue($pos++,$v, PDO::PARAM_INT); }
        $st->execute();
        $caYtd = (float)$st->fetchColumn();
    }

    // CA par mois de la période
    if ($trxHasDate) {
        $params = [];
        $sql = "SELECT strftime('%Y-%m', t.date) AS ym, COALESCE(SUM(t.amount),0) AS s
                FROM transactions t";
        if ($hasAccounts) $sql .= " JOIN accounts a ON a.id=t.account_id";
        $sql .= " WHERE t.amount > 0";
        if ($trxHasUser) { $sql .= " AND t.user_id = :u"; $params[':u'] = $userId; }
        $sql .= " AND date(t.date) >= date(:df) AND date(t.date) <= date(:dt)";
        $params[':df']=$dateFrom; $params[':dt']=$dateTo;
        if ($trxHasExcl) { $sql .= " AND COALESCE(t.exclude_from_ca,0)=0"; }
        if ($microAccountIds) {
            $in = implode(',', array_fill(0, count($microAccountIds), '?'));
            $sql .= " AND t.account_id IN ($in)";
            $params = array_merge($params, $microAccountIds);
        } else {
            $sql .= " AND 1=0";
        }
        $sql .= " GROUP BY ym ORDER BY ym ASC";
        $st = $pdo->prepare($sql);
        foreach ($params as $k=>$v) { if (is_string($k)) $st->bindValue($k,$v); }
        $pos = 1; foreach ($params as $k=>$v) { if (is_int($k)) $st->bindValue($pos++,$v, PDO::PARAM_INT); }
        $st->execute();
        $byMonth = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

/* Tarifs / plafonds micro */
$rateSocial = (float)($micro['social_contrib_rate'] ?? 0.0);
$rateTax    = (float)($micro['income_tax_rate'] ?? 0.0); // 0 si pas de versement libératoire
$plafCA     = (float)($micro['ca_ceiling'] ?? 0.0);
$vatCeil    = (float)($micro['vat_ceiling'] ?? 0.0);
$vatMajor   = (float)($micro['vat_ceiling_major'] ?? 0.0);
$activity   = (string)($micro['activity_code'] ?? '');
$declPeriod = (string)($micro['declaration_period'] ?? 'quarterly');
$vlOn       = $rateTax > 0.0; // indicateur simple

$chargesSociales = $rateSocial * $caPeriod;
$impotLiberatoire= $rateTax    * $caPeriod;

$ytdPctPlaf = ($plafCA > 0) ? min(100, ($caYtd / $plafCA) * 100) : 0;

/* Comptes Micro présents ? */
$hasMicroAcc = count($microAccountIds) > 0;

/* URL utilitaires */
$accountsUrl = 'accounts.php';
$settingsUrl = 'settings.php';

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Micro</title>
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

  <?php if (!$hasAccounts || !$hasTrx): ?>
    <div class="alert alert-warning">
      Schéma incomplet: table <code>accounts</code> ou <code>transactions</code> manquante.
    </div>
  <?php elseif (!$accHasMicro): ?>
    <div class="alert alert-warning">
      Votre schéma ne comporte pas la colonne <code>accounts.micro_enterprise_id</code>. Créez-la pour rattacher un compte “Micro”.
    </div>
  <?php elseif (!$hasMicroTable || !$micro): ?>
    <div class="alert alert-info">
      Aucune micro‑entreprise n’est configurée pour votre utilisateur.
      Allez dans <a href="<?= h($accountsUrl) ?>">Comptes</a> et créez un compte de type “Micro” pour initialiser les barèmes.
    </div>
  <?php elseif (!$hasMicroAcc): ?>
    <div class="alert alert-info">
      Aucun compte rattaché à la micro‑entreprise. Créez un compte “Micro” dans <a href="<?= h($accountsUrl) ?>">Comptes</a>.
    </div>
  <?php endif; ?>

  <!-- Filtres période -->
  <div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>Période</strong></div>
    <div class="card-body">
      <form class="row g-2" method="get">
        <div class="col-sm-4 col-md-3">
          <label class="form-label">Du</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>">
        </div>
        <div class="col-sm-4 col-md-3">
          <label class="form-label">Au</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= h($dateTo) ?>">
        </div>
        <div class="col-sm-4 col-md-3 d-flex align-items-end">
          <button class="btn btn-primary btn-sm">Appliquer</button>
          <a class="btn btn-outline-secondary btn-sm ms-2" href="micro_index.php">Réinitialiser</a>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <div class="form-text ms-md-auto">
            Déclaration: <strong><?= $declPeriod==='monthly'?'Mensuelle':'Trimestrielle' ?></strong> • Activité: <strong><?= h($activity ?: '—') ?></strong>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Cartes de synthèse -->
  <div class="row g-3">
    <div class="col-md-3">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>CA période</strong></div>
        <div class="card-body">
          <div class="display-6 mono"><?= fmt($caPeriod) ?> €</div>
          <div class="text-muted small">Du <?= h($dateFrom) ?> au <?= h($dateTo) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Charges sociales</strong></div>
        <div class="card-body">
          <div class="display-6 mono"><?= fmt($chargesSociales) ?> €</div>
          <div class="text-muted small">Taux: <?= number_format($rateSocial*100,2,',',' ') ?> %</div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Impôt libératoire</strong></div>
        <div class="card-body">
          <div class="display-6 mono"><?= fmt($impotLiberatoire) ?> €</div>
          <div class="text-muted small">Taux: <?= number_format($rateTax*100,2,',',' ') ?> % <?= $vlOn ? '' : '(non appliqué)' ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Plafond CA (YTD)</strong></div>
        <div class="card-body">
          <div class="mono mb-1"><?= fmt($caYtd) ?> € / <?= $plafCA>0?fmt($plafCA).' €':'—' ?></div>
          <div class="progress" role="progressbar" aria-valuenow="<?= (int)$ytdPctPlaf ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width: <?= (int)$ytdPctPlaf ?>%"><?= (int)$ytdPctPlaf ?>%</div>
          </div>
          <?php if ($vatCeil>0 || $vatMajor>0): ?>
            <div class="small mt-2 text-muted">
              TVA: seuil normal <?= $vatCeil>0?fmt($vatCeil).' €':'—' ?> • majoré <?= $vatMajor>0?fmt($vatMajor).' €':'—' ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Détail par mois -->
  <div class="card shadow-sm mt-3">
    <div class="card-header py-2"><strong>CA par mois</strong></div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Mois</th>
              <th class="text-end">CA (€)</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$byMonth): ?>
            <tr><td colspan="2" class="text-muted">Aucune écriture dans la période.</td></tr>
          <?php else: foreach ($byMonth as $r): ?>
            <tr>
              <td><?= h((string)$r['ym']) ?></td>
              <td class="text-end mono"><?= fmt((float)($r['s'] ?? 0)) ?> €</td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($hasAccounts && $hasTrx && $accHasMicro && !$hasMicroAcc): ?>
    <div class="alert alert-warning mt-3">
      Astuce: créez un compte “Micro” dans la page <a href="<?= h($accountsUrl) ?>">Comptes</a> pour commencer à suivre votre CA et vos cotisations automatiquement.
    </div>
  <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>