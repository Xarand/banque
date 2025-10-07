<?php declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database, MicroEnterpriseRepository};

Util::startSession();
Util::requireAuth();

$db   = new Database();
$repo = new MicroEnterpriseRepository($db->pdo());
$userId = Util::currentUserId();

$error = null;

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form']??'')==='create'){
  try{
    Util::checkCsrf();
    $repo->createMicro(
      $userId,
      $_POST['name'] ?? '',
      $_POST['regime'] ?? null,
      ($_POST['ca_ceiling'] ?? '')!=='' ? (float)$_POST['ca_ceiling'] : null,
      ($_POST['tva_ceiling'] ?? '')!=='' ? (float)$_POST['tva_ceiling'] : null,
      $_POST['primary_color'] ?? null,
      $_POST['secondary_color'] ?? null
    );
    Util::addFlash('success','Micro-entreprise créée.');
    Util::redirect('micro_index.php');
  }catch(Throwable $e){
    $error = $e->getMessage();
  }
}

$micros = $repo->listMicro($userId);
function h($v){ return App\Util::h((string)$v); }
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Micro-entreprises</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-md navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">Banque</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="index.php">Tableau</a></li>
        <li class="nav-item"><a class="nav-link" href="reports.php">Rapports</a></li>
        <li class="nav-item"><a class="nav-link active" href="micro_index.php">Micro</a></li>
      </ul>
      <a href="logout.php" class="btn btn-sm btn-outline-light">Déconnexion</a>
    </div>
  </div>
</nav>
<div class="container pb-5">
  <?php foreach(App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="row">
    <div class="col-md-5">
      <h1 class="h5 mb-3">Nouvelle micro-entreprise</h1>
      <?php if($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
      <form method="post" class="card p-3 shadow-sm">
        <?= App\Util::csrfInput() ?>
        <input type="hidden" name="form" value="create">
        <div class="mb-2">
          <label class="form-label">Nom</label>
          <input name="name" class="form-control" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Régime (optionnel)</label>
          <input name="regime" class="form-control" placeholder="ex: BIC, BNC, mixte">
        </div>
        <div class="mb-2">
          <label class="form-label">Plafond CA (€)</label>
          <input name="ca_ceiling" type="number" step="0.01" class="form-control" placeholder="ex: 188700">
        </div>
        <div class="mb-2">
          <label class="form-label">Plafond TVA (€)</label>
          <input name="tva_ceiling" type="number" step="0.01" class="form-control" placeholder="ex: 101000">
        </div>
        <div class="mb-2">
          <label class="form-label">Couleur primaire</label>
          <input name="primary_color" type="color" class="form-control form-control-color">
        </div>
        <div class="mb-3">
          <label class="form-label">Couleur secondaire</label>
          <input name="secondary_color" type="color" class="form-control form-control-color">
        </div>
        <button class="btn btn-primary">Créer</button>
      </form>
    </div>
    <div class="col-md-7">
      <h2 class="h5 mb-3">Mes micro-entreprises</h2>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead><tr>
            <th>Nom</th><th>Régime</th><th>Plafond CA</th><th>Plafond TVA</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach($micros as $m): ?>
            <tr>
              <td><?= h($m['name']) ?></td>
              <td><?= h($m['regime'] ?? '') ?></td>
              <td><?= $m['ca_ceiling']!==null ? number_format((float)$m['ca_ceiling'],2,',',' ') : '—' ?></td>
              <td><?= $m['tva_ceiling']!==null ? number_format((float)$m['tva_ceiling'],2,',',' ') : '—' ?></td>
              <td><a class="btn btn-sm btn-outline-primary" href="micro_view.php?id=<?= (int)$m['id'] ?>">Ouvrir</a></td>
            </tr>
          <?php endforeach; if(!$micros): ?>
            <tr><td colspan="5" class="text-muted">Aucune micro-entreprise.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>