<?php declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database, MicroEnterpriseRepository, FinanceRepository};

Util::startSession();
Util::requireAuth();

$db        = new Database();
$pdo       = $db->pdo();
$microRepo = new MicroEnterpriseRepository($pdo);
$finRepo   = new FinanceRepository($db);
$userId    = Util::currentUserId();

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$micro = $id > 0 ? $microRepo->getMicro($userId, $id) : null;
if (!$micro) {
    Util::addFlash('danger','Micro-entreprise introuvable.');
    Util::redirect('micro_index.php');
}

/* =====================
   Actions POST
   ===================== */
$year = isset($_GET['year']) && preg_match('/^\d{4}$/', $_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$errorCat  = null;
$errorUpd  = null;
$errorLink = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $form = $_POST['form'] ?? '';
    try {
        Util::checkCsrf();
        switch($form) {
            case 'micro_cat':
                $microRepo->createMicroCategory(
                    $userId,
                    $id,
                    $_POST['name'] ?? '',
                    $_POST['type'] ?? ''
                );
                Util::addFlash('success','Catégorie micro ajoutée.');
                Util::redirect("micro_view.php?id={$id}&year={$year}");
                break;

            case 'update_micro':
                $microRepo->updateMicro($userId,$id,[
                    'name'          => $_POST['name'] ?? $micro['name'],
                    'regime'        => $_POST['regime'] ?? null,
                    'ca_ceiling'    => ($_POST['ca_ceiling']??'')!=='' ? (float)$_POST['ca_ceiling'] : null,
                    'tva_ceiling'   => ($_POST['tva_ceiling']??'')!=='' ? (float)$_POST['tva_ceiling'] : null,
                    'primary_color' => $_POST['primary_color'] ?? null,
                    'secondary_color'=>$_POST['secondary_color'] ?? null,
                ]);
                Util::addFlash('success','Micro mise à jour.');
                Util::redirect("micro_view.php?id={$id}&year={$year}");
                break;

            case 'attach_account':
                $accId = (int)($_POST['account_id'] ?? 0);
                if ($accId <= 0) throw new RuntimeException("Compte invalide.");
                $microRepo->attachAccount($userId,$id,$accId);
                Util::addFlash('success','Compte rattaché.');
                Util::redirect("micro_view.php?id={$id}&year={$year}");
                break;

            case 'detach_account':
                $accId = (int)($_POST['account_id'] ?? 0);
                if ($accId <= 0) throw new RuntimeException("Compte invalide.");
                $microRepo->detachAccount($userId,$accId);
                Util::addFlash('success','Compte détaché.');
                Util::redirect("micro_view.php?id={$id}&year={$year}");
                break;
        }
    } catch(Throwable $e) {
        if ($form === 'micro_cat')  { $errorCat  = $e->getMessage(); }
        elseif ($form === 'update_micro') { $errorUpd  = $e->getMessage(); }
        else { $errorLink = $e->getMessage(); }
    }
}

/* =====================
   Données
   ===================== */
$overview   = $microRepo->getMicroOverview($userId,$id,$year);
$microCats  = $microRepo->listMicroCategories($userId,$id);
$allAccounts= $finRepo->listAccounts($userId);

$attached   = [];
$unassigned = [];
foreach($allAccounts as $a) {
    if ((int)($a['micro_enterprise_id'] ?? 0) === $id) {
        $attached[] = $a;
    } elseif (empty($a['micro_enterprise_id'])) {
        $unassigned[] = $a;
    }
}

/* Préparation graphique */
$labels=[]; $dataCredits=[]; $dataDebits=[];
for($m=1;$m<=12;$m++){
    $ym = sprintf('%04d-%02d',$year,$m);
    $labels[]=$ym;
    $found = null;
    foreach($overview['monthly'] as $row){
        if($row['ym']===$ym){ $found=$row; break; }
    }
    if($found){
        $dataCredits[]=(float)$found['credits'];
        $dataDebits[]=(float)$found['debits'];
    } else {
        $dataCredits[]=0; $dataDebits[]=0;
    }
}

function h($v){ return App\Util::h((string)$v); }
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Micro-entreprise - <?= h($micro['name']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
<style>
:root {
  --micro-primary: <?= h($micro['primary_color'] ?: '#0d6efd') ?>;
  --micro-secondary: <?= h($micro['secondary_color'] ?: '#6610f2') ?>;
}
.micro-bar { height:8px; background:#e9ecef; border-radius:4px; position:relative; }
.micro-bar > span { position:absolute; top:0; left:0; height:8px; border-radius:4px; background:var(--micro-primary); }
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
        <li class="nav-item"><a class="nav-link" href="reports.php">Rapports</a></li>
        <li class="nav-item"><a class="nav-link" href="micro_index.php">Micro</a></li>
        <li class="nav-item"><a class="nav-link active" href="#">Vue</a></li>
      </ul>
      <a href="logout.php" class="btn btn-sm btn-outline-light">Déconnexion</a>
    </div>
  </div>
</nav>

<div class="container pb-5">
  <?php foreach(App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h5 mb-0">
      Micro : <?= h($micro['name']) ?> (<?= h((string)$year) ?>)
    </h1>
    <form method="get" class="d-flex gap-2">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <select name="year" class="form-select form-select-sm">
        <?php for($y=(int)date('Y')+1;$y>=date('Y')-5;$y--): ?>
          <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
      <button class="btn btn-sm btn-outline-primary">Aller</button>
    </form>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-sm-4">
      <div class="summary-box">
        <h3>Chiffre d'affaires</h3>
        <div class="fs-5"><?= number_format($overview['credits'],2,',',' ') ?> €</div>
        <div class="micro-bar mt-2">
          <span style="width:<?= $overview['ca_usage_pct']!==null ? $overview['ca_usage_pct'] : 0 ?>%;"></span>
        </div>
        <div class="small mt-1 text-muted">
          Plafond: <?= $overview['ca_ceiling'] ? number_format($overview['ca_ceiling'],0,',',' ') .' €' : '—' ?>
          <?php if($overview['ca_usage_pct']!==null): ?>
             (<?= $overview['ca_usage_pct'] ?> %)
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="summary-box">
        <h3>Dépenses</h3>
        <div class="fs-5 text-danger">-<?= number_format($overview['debits'],2,',',' ') ?> €</div>
        <div class="small text-muted mt-2">Net: 
          <strong class="<?= $overview['net']<0?'text-danger':'text-success' ?>">
            <?= number_format($overview['net'],2,',',' ') ?> €
          </strong>
        </div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="summary-box">
        <h3>Plafond TVA</h3>
        <div><?= $overview['tva_ceiling'] ? number_format($overview['tva_ceiling'],0,',',' ').' €' : '—' ?></div>
        <div class="micro-bar mt-2">
          <span style="background:var(--micro-secondary);width:<?= $overview['tva_usage_pct']!==null ? $overview['tva_usage_pct'] : 0 ?>%;"></span>
        </div>
        <div class="small mt-1 text-muted">
          Usage: <?= $overview['tva_usage_pct']!==null ? $overview['tva_usage_pct'].' %' : '—' ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Graph -->
  <div class="card p-3 mb-4 shadow-sm">
    <h2 class="h6 mb-3">Flux mensuels</h2>
    <canvas id="microChart" height="140"></canvas>
  </div>

  <div class="row g-4">
    <div class="col-lg-6">
      <h3 class="h6 mb-2">Catégories micro</h3>
      <?php if($errorCat): ?><div class="alert alert-danger py-2"><?= h($errorCat) ?></div><?php endif; ?>
      <form method="post" class="card p-2 mb-3">
        <?= App\Util::csrfInput() ?>
        <input type="hidden" name="form" value="micro_cat">
        <div class="row g-2">
          <div class="col-7">
            <input name="name" class="form-control form-control-sm" placeholder="Nom" required>
          </div>
          <div class="col-3">
            <select name="type" class="form-select form-select-sm">
              <option value="income">Revenu</option>
              <option value="expense">Dépense</option>
            </select>
          </div>
          <div class="col-2">
            <button class="btn btn-sm btn-primary w-100">+</button>
          </div>
        </div>
      </form>
      <table class="table table-sm">
        <thead><tr><th>Nom</th><th>Type</th></tr></thead>
        <tbody>
        <?php foreach($microCats as $c): ?>
          <tr>
            <td><?= h($c['name']) ?></td>
            <td><?= $c['type']==='income'?'Revenu':'Dépense' ?></td>
          </tr>
        <?php endforeach; if(!$microCats): ?>
          <tr><td colspan="2" class="text-muted">Aucune catégorie.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <h3 class="h6 mt-4 mb-2">Comptes rattachés</h3>
      <?php if($errorLink): ?><div class="alert alert-danger py-2"><?= h($errorLink) ?></div><?php endif; ?>
      <ul class="list-group mb-2 small">
        <?php foreach($attached as $ac): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>
              <?= h($ac['name']) ?>
              <span class="text-muted">(
                <?= number_format((float)$ac['current_balance'],2,',',' ') ?> €)
              </span>
            </span>
            <form method="post" class="ms-2" onsubmit="return confirm('Détacher ce compte ?');">
              <?= App\Util::csrfInput() ?>
              <input type="hidden" name="form" value="detach_account">
              <input type="hidden" name="account_id" value="<?= (int)$ac['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">×</button>
            </form>
          </li>
        <?php endforeach; if(!$attached): ?>
          <li class="list-group-item text-muted">Aucun compte rattaché.</li>
        <?php endif; ?>
      </ul>

      <?php if($unassigned): ?>
      <form method="post" class="d-flex gap-2 align-items-end">
        <?= App\Util::csrfInput() ?>
        <input type="hidden" name="form" value="attach_account">
        <div class="flex-grow-1">
          <label class="form-label mb-1 small">Rattacher un compte</label>
          <select name="account_id" class="form-select form-select-sm" required>
            <option value="">(Choisir)</option>
            <?php foreach($unassigned as $ua): ?>
              <option value="<?= (int)$ua['id'] ?>"><?= h($ua['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-sm btn-outline-primary mt-3">Ajouter</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="col-lg-6">
      <h3 class="h6 mb-2">Paramètres micro</h3>
      <?php if($errorUpd): ?><div class="alert alert-danger py-2"><?= h($errorUpd) ?></div><?php endif; ?>
      <form method="post" class="card p-3 mb-4">
        <?= App\Util::csrfInput() ?>
        <input type="hidden" name="form" value="update_micro">
        <div class="mb-2">
          <label class="form-label">Nom</label>
          <input name="name" class="form-control form-control-sm" value="<?= h($micro['name']) ?>">
        </div>
        <div class="mb-2">
          <label class="form-label">Régime</label>
          <input name="regime" class="form-control form-control-sm" value="<?= h($micro['regime'] ?? '') ?>">
        </div>
        <div class="mb-2">
          <label class="form-label">Plafond CA (€)</label>
          <input name="ca_ceiling" type="number" step="0.01" class="form-control form-control-sm" value="<?= h((string)($micro['ca_ceiling'] ?? '')) ?>">
        </div>
        <div class="mb-2">
          <label class="form-label">Plafond TVA (€)</label>
          <input name="tva_ceiling" type="number" step="0.01" class="form-control form-control-sm" value="<?= h((string)($micro['tva_ceiling'] ?? '')) ?>">
        </div>
        <div class="row g-2">
          <div class="col">
            <label class="form-label">Couleur primaire</label>
            <input name="primary_color" type="color" class="form-control form-control-color" value="<?= h($micro['primary_color'] ?: '#0d6efd') ?>">
          </div>
          <div class="col">
            <label class="form-label">Couleur secondaire</label>
            <input name="secondary_color" type="color" class="form-control form-control-color" value="<?= h($micro['secondary_color'] ?: '#6610f2') ?>">
          </div>
        </div>
        <div class="mt-3">
          <button class="btn btn-sm btn-primary">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('microChart');
const chart = new Chart(ctx, {
  type:'bar',
  data:{
    labels: <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>,
    datasets:[
      {
        type:'line',
        label:'Revenus',
        data: <?= json_encode($dataCredits, JSON_UNESCAPED_UNICODE) ?>,
        borderColor:getComputedStyle(document.documentElement).getPropertyValue('--micro-primary').trim() || '#0d6efd',
        backgroundColor:'rgba(0,0,0,0)',
        tension:.25,
        yAxisID:'y'
      },
      {
        type:'bar',
        label:'Dépenses',
        data: <?= json_encode($dataDebits, JSON_UNESCAPED_UNICODE) ?>,
        backgroundColor:getComputedStyle(document.documentElement).getPropertyValue('--micro-secondary').trim() || '#6610f2',
        yAxisID:'y'
      }
    ]
  },
  options:{
    responsive:true,
    interaction:{mode:'index', intersect:false},
    stacked:false,
    scales:{ y:{beginAtZero:true} }
  }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>