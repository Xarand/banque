<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/bootstrap.php';

use App\{
    Util,
    Database,
    MicroEnterpriseRepository
};

Util::startSession();
Util::requireAuth();

$pdo       = (new Database())->pdo();
$microRepo = new MicroEnterpriseRepository($pdo);
$userId    = Util::currentUserId();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$micro = $microRepo->getMicro($userId, $id);
if (!$micro) {
    Util::addFlash('danger','Micro introuvable.');
    Util::redirect('index.php');
    exit;
}

// Recalcule AUTOMATIQUEMENT la période courante à l’ouverture de la page
try {
    $microRepo->generateOrRefreshCurrentPeriod($userId, (int)$micro['id'], new DateTimeImmutable());
} catch (Throwable $e) {
    error_log('Recalc period failed: '.$e->getMessage());
}

// Admin facultatif (pour recalquer plafonds si besoin)
$isAdmin = false;
try {
    $stA = $pdo->prepare("SELECT is_admin FROM users WHERE id=:id");
    $stA->execute([':id'=>$userId]);
    $isAdmin = (int)$stA->fetchColumn() === 1;
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        Util::checkCsrf();
        if (isset($_POST['sync_this']) && $isAdmin) {
            if (!empty($micro['activity_code'])) {
                $stR = $pdo->prepare("SELECT ca_ceiling,tva_ceiling,tva_ceiling_major FROM micro_activity_rates WHERE code=:c");
                $stR->execute([':c'=>$micro['activity_code']]);
                if ($bar = $stR->fetch(PDO::FETCH_ASSOC)) {
                    $up = $pdo->prepare("
                        UPDATE micro_enterprises SET
                          ca_ceiling=:ca,
                          tva_ceiling=:tva,
                          tva_ceiling_major=:tvaM,
                          updated_at=datetime('now')
                        WHERE id=:id AND user_id=:u
                    ");
                    $up->execute([
                        ':ca'=>$bar['ca_ceiling'],
                        ':tva'=>$bar['tva_ceiling'],
                        ':tvaM'=>$bar['tva_ceiling_major'] ?? null,
                        ':id'=>$micro['id'],
                        ':u'=>$userId
                    ]);
                    Util::addFlash('success','Plafonds recalqués depuis le barème.');
                }
            }
        }
    } catch (Throwable $e) {
        Util::addFlash('danger',$e->getMessage());
    }
    Util::redirect('micro_view.php?id='.$micro['id']);
    exit;
}

// Label activité
$activityLabel = null;
if (!empty($micro['activity_code'])) {
    $st = $pdo->prepare("SELECT label FROM micro_activity_rates WHERE code=:c");
    $st->execute([':c'=>$micro['activity_code']]);
    $activityLabel = $st->fetchColumn() ?: null;
}

// CA année courante (crédits uniquement)
$year = (int)date('Y');
$stYear = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN t.amount>0 THEN t.amount ELSE 0 END),0)
    FROM transactions t
    JOIN accounts a ON a.id=t.account_id
    WHERE a.micro_enterprise_id=:m
      AND t.user_id=:u
      AND t.exclude_from_ca=0
      AND strftime('%Y', t.date)=:y
");
$stYear->execute([
    ':m'=>$micro['id'],
    ':u'=>$userId,
    ':y'=>(string)$year
]);
$caYear = (float)$stYear->fetchColumn();

$caCeiling  = $micro['ca_ceiling'] !== null ? (float)$micro['ca_ceiling'] : null;
$tvaCeil    = $micro['tva_ceiling'] !== null ? (float)$micro['tva_ceiling'] : null;
$tvaCeilMaj = array_key_exists('tva_ceiling_major',$micro) && $micro['tva_ceiling_major'] !== null
    ? (float)$micro['tva_ceiling_major'] : null;

function h(string $v): string { return App\Util::h($v); }
function fmt(?float $n): string { return $n===null ? '—' : number_format($n,0,',',' '); }
function ratio(?float $val, ?float $ceil): ?float {
    if ($val===null || $ceil===null || $ceil<=0) return null;
    $r = $val / $ceil;
    return $r < 0 ? 0 : ($r > 1 ? 1 : $r);
}
$rCa   = ratio($caYear, $caCeiling);
$rTva  = ratio($caYear, $tvaCeil);
$rTvaM = ratio($caYear, $tvaCeilMaj);

// Périodes (dernières)
$periods = [];
try {
    $stP = $pdo->prepare("
        SELECT *
        FROM micro_contribution_periods
        WHERE micro_id=:m
        ORDER BY period_start DESC
        LIMIT 12
    ");
    $stP->execute([':m'=>$micro['id']]);
    $periods = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?= h($micro['name'] ?? 'Micro') ?> – Micro-entreprise</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f5f6f8; }
.progress { background:#e9ecef; }
.progress .progress-bar { font-size: .75rem; }
.card small.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
</style>
</head>
<body>

<?php include __DIR__.'/_nav.php'; ?>

<div class="container py-3">

  <?php foreach (Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2 mb-3"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h5 mb-0"><?= h($micro['name'] ?? 'Micro') ?></h1>
    <div>
      <a href="index.php" class="btn btn-sm btn-secondary">Aller aux comptes</a>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card shadow-sm mb-3">
        <div class="card-header py-2"><strong>Informations</strong></div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-5 text-muted">Activité</div>
            <div class="col-7"><?= h(($micro['activity_code'] ?? '—').($activityLabel ? ' — '.$activityLabel : '')) ?></div>

            <div class="col-5 text-muted">CA plafond</div>
            <div class="col-7"><?= fmt($caCeiling) ?> €</div>

            <div class="col-5 text-muted">TVA (seuil)</div>
            <div class="col-7"><?= fmt($tvaCeil) ?> €</div>

            <div class="col-5 text-muted">TVA (majoré)</div>
            <div class="col-7"><?= fmt($tvaCeilMaj) ?> €</div>

            <div class="col-5 text-muted">Fréquence</div>
            <div class="col-7"><?= h($micro['contributions_frequency'] ?? 'quarterly') ?></div>

            <div class="col-5 text-muted">IR libératoire</div>
            <div class="col-7"><?= !empty($micro['ir_liberatoire']) ? 'Oui' : 'Non' ?></div>
          </div>

          <?php if ($isAdmin): ?>
          <hr>
          <form method="post" class="d-inline ms-2" onsubmit="return confirm('Recalquer les plafonds sur le barème ?');">
            <?= Util::csrfInput() ?>
            <button class="btn btn-sm btn-outline-secondary" name="sync_this" value="1">
              Recalquer plafonds
            </button>
          </form>
          <?php endif; ?>

        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card shadow-sm mb-3">
        <div class="card-header py-2"><strong>Progression CA <?= (int)$year ?></strong></div>
        <div class="card-body">
          <p class="mb-1">
            CA cumulé: <strong><?= number_format($caYear,0,',',' ') ?> €</strong>
            <?php if($caCeiling): ?>
              / <?= number_format($caCeiling,0,',',' ') ?> €
            <?php endif; ?>
          </p>

          <?php if(($rCa = ratio($caYear, $caCeiling)) !== null): ?>
            <?php $cls = $rCa < 0.6 ? 'bg-success' : ($rCa < 0.8 ? 'bg-warning' : 'bg-danger'); ?>
            <div class="progress mb-3" style="height:18px;">
              <div class="progress-bar <?= $cls ?>" style="width: <?= round($rCa*100,1) ?>%;">
                <?= round($rCa*100,1) ?>%
              </div>
            </div>
          <?php endif; ?>

          <?php if($tvaCeil): ?>
            <div class="mb-2">
              <div class="d-flex justify-content-between">
                <small class="text-muted">Seuil TVA normal</small>
                <small class="mono"><?= number_format($tvaCeil,0,',',' ') ?> €</small>
              </div>
              <?php if(($rTva = ratio($caYear, $tvaCeil)) !== null): ?>
                <?php $clsTva = $rTva < 0.6 ? 'bg-info' : ($rTva < 0.8 ? 'bg-warning' : 'bg-danger'); ?>
                <div class="progress" style="height:14px;">
                  <div class="progress-bar <?= $clsTva ?>" style="width: <?= round($rTva*100,1) ?>%;"></div>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if($tvaCeilMaj): ?>
            <div class="mb-2">
              <div class="d-flex justify-content-between">
                <small class="text-muted">Seuil TVA majoré</small>
                <small class="mono"><?= number_format($tvaCeilMaj,0,',',' ') ?> €</small>
              </div>
              <?php if(($rTvaM = ratio($caYear, $tvaCeilMaj)) !== null): ?>
                <?php $clsTvaM = $rTvaM < 0.6 ? 'bg-info' : ($rTvaM < 0.8 ? 'bg-warning' : 'bg-danger'); ?>
                <div class="progress" style="height:14px;">
                  <div class="progress-bar <?= $clsTvaM ?>" style="width: <?= round($rTvaM*100,1) ?>%;"></div>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if($tvaCeil && $caYear >= $tvaCeil && ($tvaCeilMaj===null || $caYear < $tvaCeilMaj)): ?>
            <div class="alert alert-warning mt-3 py-2 mb-0">
              Seuil TVA normal atteint. Surveille le seuil majoré.
            </div>
          <?php elseif($tvaCeilMaj && $caYear >= $tvaCeilMaj): ?>
            <div class="alert alert-danger mt-3 py-2 mb-0">
              Seuil TVA majoré dépassé, régime TVA à ajuster.
            </div>
          <?php endif; ?>

        </div>
      </div>

      <?php if($periods): ?>
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Dernières périodes</strong></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th>Période</th>
                  <th>CA</th>
                  <th>Total dû</th>
                  <th>Statut</th>
                  <th>Échéance</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($periods as $p): ?>
                <tr>
                  <td><?= h($p['period_key']) ?></td>
                  <td><?= number_format((float)$p['ca_amount'],0,',',' ') ?> €</td>
                  <td>
                    <?php
                      // Fallback: si total_due est NULL (anciennes lignes), calcule à l’affichage
                      $td = $p['total_due'];
                      if ($td === null) {
                          $hasRate = ($p['social_rate_used'] !== null) || ($p['ir_rate_used'] !== null) || ($p['cfp_rate_used'] !== null) || ($p['chamber_rate_used'] !== null);
                          if ($hasRate) {
                              $sum = ($p['social_due'] ?? 0) + ($p['ir_due'] ?? 0) + ($p['cfp_due'] ?? 0) + ($p['chamber_due'] ?? 0);
                              $td = round((float)$sum, 2);
                          }
                      }
                      echo $td !== null ? number_format((float)$td,2,',',' ').' €' : '—';
                    ?>
                  </td>
                  <td>
                    <?php if(($p['status'] ?? '')==='paid'): ?>
                      <span class="badge bg-success">payée</span>
                    <?php else: ?>
                      <span class="badge bg-secondary">en attente</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h($p['due_date'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>