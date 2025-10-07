<?php declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database, MicroEnterpriseRepository, MicroActivityRepository};

Util::startSession();
Util::requireAuth();

$db        = new Database();
$pdo       = $db->pdo();
$microRepo = new MicroEnterpriseRepository($pdo);
$actRepo   = new MicroActivityRepository($pdo);
$userId    = Util::currentUserId();

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$year  = isset($_GET['year']) && preg_match('/^\d{4}$/', $_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$micro = $microRepo->getMicro($userId,$id);
if(!$micro){
    Util::addFlash('danger','Micro-entreprise introuvable.');
    Util::redirect('micro_index.php');
}

$activities = $actRepo->listAll();
$errUpdate = null;
$errPay    = null;

/* Actions POST */
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        Util::checkCsrf();
        $form = $_POST['form'] ?? '';
        if($form==='update_micro'){
            $microRepo->updateMicro($userId,$id,[
                'name'=>$_POST['name'] ?? $micro['name'],
                'activity_code'=>$_POST['activity_code'] ?? null,
                'contributions_frequency'=>$_POST['contributions_frequency'] ?? null,
                'ir_liberatoire'=> isset($_POST['ir_liberatoire']) ? 1 : 0,
                'creation_date'=>$_POST['creation_date'] ?? null,
                'region'=>$_POST['region'] ?? null,
                'acre_reduction_rate'=> ($_POST['acre_reduction_rate'] ?? '')!=='' ? (float)$_POST['acre_reduction_rate'] : null,
                'ca_ceiling'=>($_POST['ca_ceiling']??'')!=='' ? (float)$_POST['ca_ceiling'] : null,
                'tva_ceiling'=>($_POST['tva_ceiling']??'')!=='' ? (float)$_POST['tva_ceiling'] : null,
            ]);
            Util::addFlash('success','Paramètres micro mis à jour.');
            Util::redirect("micro_view.php?id={$id}&year={$year}");
        } elseif($form==='mark_paid'){
            $pid = (int)($_POST['period_id'] ?? 0);
            $microRepo->markPeriodPaid($userId,$pid);
            Util::addFlash('success','Période marquée payée.');
            Util::redirect("micro_view.php?id={$id}&year={$year}");
        } elseif($form==='refresh_current'){
            $microRepo->generateOrRefreshCurrentPeriod($userId,$id,new DateTimeImmutable());
            Util::addFlash('success','Période recalculée.');
            Util::redirect("micro_view.php?id={$id}&year={$year}");
        }
    }catch(Throwable $e){
        if(($form ?? '')==='mark_paid') $errPay = $e->getMessage();
        else $errUpdate = $e->getMessage();
    }
}

/* Calcul global année + refresh période courante */
$microRepo->generateOrRefreshCurrentPeriod($userId,$id,new DateTimeImmutable());

$yearStats = $microRepo->computeYearToDate($userId,$id,$year);
$periods   = $microRepo->listContributionPeriods($userId,$id);

$rateRow   = null;
if(!empty($micro['activity_code'])){
    foreach($activities as $ac){
        if($ac['code']===$micro['activity_code']){
            $rateRow=$ac; break;
        }
    }
}

$caCeiling = $micro['ca_ceiling'] ?? ($rateRow['ca_ceiling'] ?? null);
$tvaCeil   = $micro['tva_ceiling'] ?? ($rateRow['tva_ceiling'] ?? null);

$caProgress = ($caCeiling && $caCeiling>0) ? min(1.0, $yearStats['ca'] / $caCeiling) : null;
$tvaProgress= ($tvaCeil && $tvaCeil>0) ? min(1.0, $yearStats['ca'] / $tvaCeil) : null;
$tvaAlertThreshold = $rateRow['tva_alert_threshold'] ?? 0.5;
$showTvaAlert = $tvaProgress!==null && $tvaProgress >= $tvaAlertThreshold;

function h($v){ return App\Util::h((string)$v); }
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Micro - <?= h($micro['name']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
<style>
.bar-track {height:10px; background:#e9ecef; border-radius:5px; position:relative; overflow:hidden;}
.bar-fill {height:100%; background:#0d6efd; display:block;}
.bar-fill.tva { background:#17a2b8; }
.badge-alert { background:#ffc107; color:#000; }
</style>
</head>
<body>
<nav class="navbar navbar-expand-md navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">Banque</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="navMain" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="index.php">Tableau</a></li>
        <li class="nav-item"><a class="nav-link" href="micro_index.php">Micro (liste)</a></li>
        <li class="nav-item"><a class="nav-link active" href="#">Détails</a></li>
      </ul>
      <a href="logout.php" class="btn btn-sm btn-outline-light">Déconnexion</a>
    </div>
  </div>
</nav>

<div class="container pb-5">
  <?php foreach(App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <h1 class="h5 mb-3">Micro-entreprise : <?= h($micro['name']) ?> (année <?= h($year) ?>)</h1>

  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="card p-3">
        <h2 class="h6 mb-3">Chiffre d'affaires & TVA</h2>
        <div class="mb-2">
          CA :
          <strong><?= number_format($yearStats['ca'],2,',',' ') ?> €</strong>
          <?php if($caCeiling): ?>
            / <?= number_format($caCeiling,0,',',' ') ?> €
            <?php if($caProgress!==null): ?>
              (<?= number_format($caProgress*100,1,',',' ') ?> %)
            <?php endif; ?>
          <?php endif; ?>
          <div class="bar-track mt-1">
            <span class="bar-fill" style="width:<?= $caProgress!==null? ($caProgress*100):0 ?>%"></span>
          </div>
        </div>

        <div class="mb-2">
          Seuil TVA :
          <strong><?= $tvaCeil ? number_format($tvaCeil,0,',',' ') .' €' : '—' ?></strong>
          <?php if($tvaProgress!==null): ?>
            (<?= number_format($tvaProgress*100,1,',',' ') ?> %)
          <?php endif; ?>
          <?php if($showTvaAlert): ?>
            <span class="badge badge-alert ms-2">Alerte TVA</span>
          <?php endif; ?>
          <div class="bar-track mt-1">
            <span class="bar-fill tva" style="width:<?= $tvaProgress!==null? ($tvaProgress*100):0 ?>%"></span>
          </div>
        </div>

        <div class="mt-3 small text-muted">
          Débits (dépenses) : <?= number_format($yearStats['debits'],2,',',' ') ?> €<br>
          Net : <strong class="<?= $yearStats['net']<0?'text-danger':'text-success' ?>">
            <?= number_format($yearStats['net'],2,',',' ') ?> €
          </strong>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card p-3">
        <h2 class="h6 mb-3">Paramètres micro</h2>
        <?php if($errUpdate): ?><div class="alert alert-danger py-1"><?= h($errUpdate) ?></div><?php endif; ?>
        <form method="post" class="row g-2">
          <?= App\Util::csrfInput() ?>
          <input type="hidden" name="form" value="update_micro">
          <div class="col-12">
            <label class="form-label mb-1">Nom affiché</label>
            <input name="name" class="form-control form-control-sm" value="<?= h($micro['name']) ?>">
          </div>
          <div class="col-6">
            <label class="form-label mb-1">Activité</label>
            <select name="activity_code" class="form-select form-select-sm">
              <option value="">(Aucune)</option>
              <?php foreach($activities as $ac): ?>
                <option value="<?= h($ac['code']) ?>" <?= $micro['activity_code']===$ac['code']?'selected':'' ?>>
                  <?= h($ac['code'].' - '.$ac['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label mb-1">Fréquence</label>
            <select name="contributions_frequency" class="form-select form-select-sm">
              <option value="quarterly" <?= ($micro['contributions_frequency'] ?? '')==='quarterly'?'selected':'' ?>>Trimestrielle</option>
              <option value="monthly" <?= ($micro['contributions_frequency'] ?? '')==='monthly'?'selected':'' ?>>Mensuelle</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label mb-1">Date création</label>
            <input type="date" name="creation_date" class="form-control form-control-sm"
                   value="<?= h($micro['creation_date'] ?? '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label mb-1 d-block">IR libératoire</label>
            <div class="form-check form-switch">
              <input type="checkbox" class="form-check-input" name="ir_liberatoire" value="1"
                     <?= !empty($micro['ir_liberatoire'])?'checked':'' ?>>
            </div>
          </div>
          <div class="col-6">
            <label class="form-label mb-1">Région</label>
            <select name="region" class="form-select form-select-sm">
              <option value="" <?= empty($micro['region'])?'selected':''?>>(Std)</option>
              <option value="alsace" <?= ($micro['region'] ?? '')==='alsace'?'selected':'' ?>>Alsace</option>
              <option value="moselle" <?= ($micro['region'] ?? '')==='moselle'?'selected':'' ?>>Moselle</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label mb-1">Réduction ACRE</label>
            <input type="number" step="0.01" min="0" max="1" name="acre_reduction_rate"
                   class="form-control form-control-sm"
                   value="<?= h((string)($micro['acre_reduction_rate'] ?? '')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label mb-1">Plafond CA (override)</label>
            <input type="number" step="0.01" name="ca_ceiling" class="form-control form-control-sm"
                   value="<?= h((string)($micro['ca_ceiling'] ?? '')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label mb-1">Seuil TVA (override)</label>
            <input type="number" step="0.01" name="tva_ceiling" class="form-control form-control-sm"
                   value="<?= h((string)($micro['tva_ceiling'] ?? '')) ?>">
          </div>
          <div class="col-12 mt-2">
            <button class="btn btn-sm btn-primary">Enregistrer</button>
            <button name="form" value="refresh_current" class="btn btn-sm btn-outline-secondary ms-2">Recalcul période</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <h2 class="h6 mb-2">Échéances / Périodes</h2>
  <?php if($errPay): ?><div class="alert alert-danger py-1"><?= h($errPay) ?></div><?php endif; ?>

  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>Période</th>
          <th>Dates</th>
          <th>CA</th>
          <th>Social</th>
          <th>IR</th>
          <th>CFP</th>
          <th>Chambre</th>
          <th>Total</th>
          <th>Échéance</th>
          <th>Statut</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php
      $has = false;
      foreach($periods as $p):
        $has = true;
        $pending = $p['status']==='pending';
      ?>
        <tr>
          <td><?= h($p['period_key']) ?></td>
          <td><?= h($p['period_start']) ?> → <?= h($p['period_end']) ?></td>
          <td><?= number_format((float)$p['ca_amount'],2,',',' ') ?></td>
          <td><?= $p['social_due']!==null? number_format((float)$p['social_due'],2,',',' ') : '—' ?></td>
          <td><?= $p['ir_due']!==null? number_format((float)$p['ir_due'],2,',',' ') : '—' ?></td>
          <td><?= $p['cfp_due']!==null? number_format((float)$p['cfp_due'],2,',',' ') : '—' ?></td>
          <td><?= $p['chamber_due']!==null? number_format((float)$p['chamber_due'],2,',',' ') : '—' ?></td>
          <td><?= $p['total_due']!==null? number_format((float)$p['total_due'],2,',',' ') : '—' ?></td>
          <td><?= h($p['due_date']) ?></td>
          <td>
            <?php if($p['status']==='paid'): ?>
              <span class="badge bg-success">Payée</span>
            <?php elseif($p['status']==='pending'): ?>
              <span class="badge bg-warning text-dark">En attente</span>
            <?php else: ?>
              <span class="badge bg-secondary"><?= h($p['status']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if($pending): ?>
            <form method="post" class="d-inline">
              <?= App\Util::csrfInput() ?>
              <input type="hidden" name="form" value="mark_paid">
              <input type="hidden" name="period_id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn-sm btn-outline-success">Payée</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if(!$has): ?>
        <tr><td colspan="11" class="text-muted">Aucune période.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>