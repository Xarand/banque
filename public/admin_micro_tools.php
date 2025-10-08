<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database, MicroEnterpriseRepository};

Util::startSession();
$db  = new Database();
$pdo = $db->pdo();
Util::requireAdmin($pdo);

$repo = new MicroEnterpriseRepository($pdo);
$error = $msgSync = $msgRecalc = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        Util::checkCsrf();
        if (isset($_POST['sync'])) {
            $n = $repo->syncCeilingsFromRates();
            $msgSync = "Plafonds synchronisés sur $n micro(s).";
        } elseif (isset($_POST['recalc_all'])) {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) throw new RuntimeException("User id requis.");
            $repo->recalculateAllOpenPeriods($userId);
            $msgRecalc = "Période courante recalculée pour toutes les micros de l'utilisateur $userId.";
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$micros = $pdo->query("
    SELECT m.id,m.user_id,m.name,m.activity_code,
           m.ca_ceiling,m.tva_ceiling,
           CASE WHEN instr(group_concat(name), 'tva_ceiling_major')>0 OR 1 THEN m.tva_ceiling_major END AS tva_ceiling_major
    FROM micro_enterprises m
    ORDER BY m.user_id,m.id
")->fetchAll(PDO::FETCH_ASSOC);

function h(string $v): string { return App\Util::h($v); }
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Admin – Outils Micro</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-4">
  <h1 class="h5 mb-4">Administration – Outils Micro</h1>

  <?php foreach(Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2 mb-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>
  <?php if ($error): ?><div class="alert alert-danger py-2 mb-2"><?= h($error) ?></div><?php endif; ?>
  <?php if ($msgSync): ?><div class="alert alert-success py-2 mb-2"><?= h($msgSync) ?></div><?php endif; ?>
  <?php if ($msgRecalc): ?><div class="alert alert-success py-2 mb-2"><?= h($msgRecalc) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-md-4">
      <div class="card shadow-sm mb-3">
        <div class="card-header py-2"><strong>Synchroniser plafonds</strong></div>
        <div class="card-body">
          <p class="small text-muted">Met à jour CA / TVA / TVA maj pour chaque micro selon son activity_code.</p>
          <form method="post">
            <?= Util::csrfInput() ?>
            <button name="sync" value="1" class="btn btn-sm btn-primary"
              onclick="return confirm('Mettre à jour les plafonds de toutes les micros ?');">
              Lancer la synchronisation
            </button>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Recalcul période courante</strong></div>
        <div class="card-body">
          <p class="small text-muted">Régénère la période en cours pour toutes les micros d'un utilisateur (non payées).</p>
          <form method="post" class="d-flex gap-2">
            <?= Util::csrfInput() ?>
            <input class="form-control form-control-sm" name="user_id" type="number" min="1" placeholder="User ID" required>
            <button name="recalc_all" value="1" class="btn btn-sm btn-outline-primary"
              onclick="return confirm('Recalculer les périodes courantes pour cet utilisateur ?');">
              Recalculer
            </button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-md-8">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Micro existantes</strong></div>
        <div class="card-body p-0">
          <div class="table-responsive" style="max-height:420px;">
            <table class="table table-sm table-striped mb-0">
              <thead class="table-light">
                <tr><th>ID</th><th>User</th><th>Nom</th><th>Activité</th><th>CA</th><th>TVA</th><th>TVA maj</th></tr>
              </thead>
              <tbody>
              <?php foreach($micros as $m): ?>
                <tr>
                  <td><?= (int)$m['id'] ?></td>
                  <td><?= (int)$m['user_id'] ?></td>
                  <td><?= h($m['name']) ?></td>
                  <td><?= h($m['activity_code'] ?? '') ?></td>
                  <td><?= $m['ca_ceiling']!==null? number_format((float)$m['ca_ceiling'],0,',',' ') : '—' ?></td>
                  <td><?= $m['tva_ceiling']!==null? number_format((float)$m['tva_ceiling'],0,',',' ') : '—' ?></td>
                  <td><?= $m['tva_ceiling_major']!==null? number_format((float)$m['tva_ceiling_major'],0,',',' ') : '—' ?></td>
                </tr>
              <?php endforeach; if(!$micros): ?>
                <tr><td colspan="7" class="text-muted">Aucune micro.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <p class="small text-muted mt-2 mb-0">Après modification des barèmes, clique “Synchroniser plafonds”.</p>
    </div>
  </div>
</div>
</body>
</html>