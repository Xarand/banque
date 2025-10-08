<?php declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\{
    Util,
    Database,
    MicroEnterpriseRepository,
    MicroActivityRepository
};

Util::startSession();
Util::requireAuth();

$db        = new Database();
$pdo       = $db->pdo();
$repo      = new MicroEnterpriseRepository($pdo);
$actRepo   = new MicroActivityRepository($pdo);
$userId    = Util::currentUserId();

function h(string $v): string { return App\Util::h($v); }

/**
 * Normalise une chaîne vers ?float (FR ou EN) ou null.
 */
function parseFloat(null|string $v): ?float {
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;
    $v = str_replace(["\u{00A0}", ' '], '', $v);
    $v = str_replace(',', '.', $v);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) {
        return null;
    }
    return (float)$v;
}

/**
 * Validation simple couleur hex (#RRGGBB ou vide).
 */
function validColor(?string $c): ?string {
    if ($c === null || $c === '') return null;
    $c = trim($c);
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $c)) {
        return $c;
    }
    return null; // on ignore valeur invalide
}

$error = null;
$activities = $actRepo->listAll();
$micros = $repo->listMicro($userId);

// Si déjà une micro → (modèle micro unique) soit redirection directe, soit message.
// Ici : on affiche la liste mais on empêche la création d'une seconde.
$microAlready = count($micros) >= 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create') {
    if ($microAlready) {
        Util::addFlash('warning', "Une micro existe déjà.");
        Util::redirect('micro_view.php?id='.(int)$micros[0]['id']);
    }
    try {
        Util::checkCsrf();

        $name       = trim($_POST['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException("Nom requis.");
        }

        $caCeiling  = parseFloat($_POST['ca_ceiling'] ?? null);
        $tvaCeiling = parseFloat($_POST['tva_ceiling'] ?? null);

        $activityCode = $_POST['activity_code'] !== '' ? $_POST['activity_code'] : null;
        $frequency    = $_POST['contributions_frequency'] ?? 'quarterly';
        $irLiberatoire= !empty($_POST['ir_liberatoire']) ? 1 : 0;

        // Création
        $newId = $repo->createMicro(
            $userId,
            $name,
            $caCeiling,
            $tvaCeiling,
            $activityCode,
            $frequency,
            $irLiberatoire
        );

        // Mise à jour complémentaire (regime / couleurs)
        $updateFields = [];
        if (($reg = trim($_POST['regime'] ?? '')) !== '') {
            $updateFields['regime'] = $reg;
        }
        if (($pc = validColor($_POST['primary_color'] ?? null)) !== null) {
            $updateFields['primary_color'] = $pc;
        }
        if (($sc = validColor($_POST['secondary_color'] ?? null)) !== null) {
            $updateFields['secondary_color'] = $sc;
        }
        if ($updateFields) {
            $repo->updateMicro($userId, $newId, $updateFields);
        }

        Util::addFlash('success', 'Micro-entreprise créée.');
        Util::redirect('micro_view.php?id='.$newId);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// Recharger micro(s) après éventuelle création
$micros = $repo->listMicro($userId);
$microAlready = count($micros) >= 1;
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Micro-entreprise</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body class="bg-light">
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
        <li class="nav-item"><a class="nav-link active" href="micro_index.php">Micro</a></li>
      </ul>
      <a href="logout.php" class="btn btn-sm btn-outline-light">Déconnexion</a>
    </div>
  </div>
</nav>

<div class="container pb-5">
  <?php foreach (App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2 mb-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <div class="col-lg-5">
      <h1 class="h5 mb-3">Micro-entreprise (unique)</h1>
      <?php if ($error): ?>
        <div class="alert alert-danger py-2 mb-3"><?= h($error) ?></div>
      <?php endif; ?>

      <?php if ($microAlready): ?>
        <div class="card p-3 shadow-sm">
          <p class="mb-2">Une micro-entreprise existe déjà.</p>
          <a class="btn btn-primary btn-sm" href="micro_view.php?id=<?= (int)$micros[0]['id'] ?>">Ouvrir</a>
        </div>
      <?php else: ?>
        <form method="post" class="card p-3 shadow-sm">
          <?= App\Util::csrfInput() ?>
          <input type="hidden" name="form" value="create">

          <div class="mb-2">
            <label class="form-label">Nom</label>
            <input name="name" class="form-control form-control-sm" required>
          </div>

            <div class="mb-2">
              <label class="form-label">Activité</label>
              <select name="activity_code" class="form-select form-select-sm">
                <option value="">(Aucune)</option>
                <?php foreach($activities as $ac): ?>
                  <option value="<?= h($ac['code']) ?>">
                    <?= h($ac['code'].' - '.$ac['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label">Fréquence contributions</label>
              <select name="contributions_frequency" class="form-select form-select-sm">
                <option value="quarterly">Trimestrielle</option>
                <option value="monthly">Mensuelle</option>
              </select>
            </div>

            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" id="ir_liberatoire" name="ir_liberatoire" value="1">
              <label class="form-check-label" for="ir_liberatoire">IR libératoire</label>
            </div>

            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label">Plafond CA (€)</label>
                <input name="ca_ceiling" type="number" step="0.01" class="form-control form-control-sm" placeholder="ex: 77700">
              </div>
              <div class="col-6">
                <label class="form-label">Plafond TVA (€)</label>
                <input name="tva_ceiling" type="number" step="0.01" class="form-control form-control-sm" placeholder="ex: 39100">
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label">Régime (optionnel)</label>
              <input name="regime" class="form-control form-control-sm" placeholder="ex: BIC / BNC">
            </div>

            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label">Couleur primaire</label>
                <input name="primary_color" type="color" class="form-control form-control-color form-control-sm">
              </div>
              <div class="col-6">
                <label class="form-label">Couleur secondaire</label>
                <input name="secondary_color" type="color" class="form-control form-control-color form-control-sm">
              </div>
            </div>

            <button class="btn btn-primary btn-sm">Créer</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="col-lg-7">
      <h2 class="h6 mb-3">Résumé</h2>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
          <thead>
            <tr>
              <th>Nom</th>
              <th>Activité</th>
              <th>Fréq.</th>
              <th>CA plafond</th>
              <th>TVA plafond</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if ($micros): foreach($micros as $m): ?>
            <tr>
              <td><?= h($m['name']) ?></td>
              <td><?= h($m['activity_code'] ?? '') ?></td>
              <td><?= h($m['contributions_frequency'] ?? '') ?></td>
              <td><?= $m['ca_ceiling']!==null ? number_format((float)$m['ca_ceiling'],2,',',' ') : '—' ?></td>
              <td><?= $m['tva_ceiling']!==null ? number_format((float)$m['tva_ceiling'],2,',',' ') : '—' ?></td>
              <td>
                <a class="btn btn-sm btn-outline-primary" href="micro_view.php?id=<?= (int)$m['id'] ?>">Ouvrir</a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-muted">Aucune micro-entreprise.</td></tr>
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