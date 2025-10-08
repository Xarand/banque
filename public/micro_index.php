<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\{
    Util,
    Database,
    MicroEnterpriseRepository,
    MicroActivityRepository
};

ini_set('display_errors','1'); // (optionnel en dev)
error_reporting(E_ALL);

Util::startSession();
Util::requireAuth();

$pdo        = (new Database())->pdo();
$microRepo  = new MicroEnterpriseRepository($pdo);
$actRepo    = new MicroActivityRepository($pdo);
$userId     = Util::currentUserId();

$activities = $actRepo->listAll();   // Doit retourner 5 lignes (508, 518, 781, 781_SSI, LM_TCL)
$micros     = $microRepo->listMicro($userId);
$hasMicro   = !empty($micros);
$error      = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create') {
    if ($hasMicro) {
        Util::addFlash('warning','Une micro existe déjà.');
        Util::redirect('micro_view.php?id='.(int)$micros[0]['id']);
    }
    try {
        Util::checkCsrf();

        $name         = trim($_POST['name'] ?? '');
        $activityCode = $_POST['activity_code'] ?? '';
        $freq         = $_POST['contributions_frequency'] ?? 'quarterly';
        $ir           = !empty($_POST['ir_liberatoire']) ? 1 : 0;

        if ($name === '') {
            throw new RuntimeException('Nom requis.');
        }
        if ($activityCode === '') {
            throw new RuntimeException('Activité requise.');
        }

        $id = $microRepo->createMicro(
            $userId,
            $name,
            null,   // CA plafond auto
            null,   // TVA plafond auto
            $activityCode,
            $freq,
            $ir
        );

        Util::addFlash('success','Micro créée.');
        Util::redirect('micro_view.php?id='.$id);

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function h(string $v): string { return Util::h($v); }
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Micro-entreprise</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f5f6f8; }
.rate-preview small { display:block; line-height:1.2; }
</style>
</head>
<body>
<div class="container py-4">

  <?php foreach (Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2 mb-3"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <div class="col-lg-5">
      <h1 class="h5 mb-3">Micro-entreprise</h1>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
      <?php endif; ?>

      <?php if ($hasMicro): ?>
        <div class="alert alert-info py-2">Une micro existe déjà.</div>
        <a class="btn btn-primary btn-sm" href="micro_view.php?id=<?= (int)$micros[0]['id'] ?>">Ouvrir</a>
      <?php else: ?>
        <form method="post" class="card p-3 shadow-sm">
          <?= Util::csrfInput() ?>
          <input type="hidden" name="form" value="create">

          <div class="mb-2">
            <label class="form-label">Nom</label>
            <input name="name" class="form-control form-control-sm" required autocomplete="off">
          </div>

            <div class="mb-2">
              <label class="form-label">Activité</label>
              <select name="activity_code" id="activity" class="form-select form-select-sm" required>
                <option value="">-- Choisir --</option>
                <?php foreach ($activities as $ac): ?>
                  <?php
                    // Construction JSON fiable
                    $json = htmlspecialchars(json_encode([
                        'code'                => $ac['code'],
                        'label'               => $ac['label'],
                        'social_rate'         => $ac['social_rate'],
                        'ir_rate'             => $ac['ir_rate'],
                        'cfp_rate'            => $ac['cfp_rate'],
                        'chamber_type'        => $ac['chamber_type'],
                        'chamber_rate_default'=> $ac['chamber_rate_default'],
                        'ca_ceiling'          => $ac['ca_ceiling'],
                        'tva_ceiling'         => $ac['tva_ceiling'],
                        'tva_ceiling_major'   => $ac['tva_ceiling_major'],
                    ], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                  ?>
                  <option value="<?= h($ac['code']) ?>" data-json='<?= $json ?>'>
                    <?= h($ac['code'].' - '.$ac['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

          <div class="mb-2">
            <label class="form-label">Fréquence</label>
            <select name="contributions_frequency" class="form-select form-select-sm">
              <option value="quarterly">Trimestrielle</option>
              <option value="monthly">Mensuelle</option>
            </select>
          </div>

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="ir_liberatoire" name="ir_liberatoire" value="1">
            <label class="form-check-label" for="ir_liberatoire">IR libératoire</label>
          </div>

          <div class="border rounded p-2 mb-3 rate-preview bg-white">
            <div class="fw-bold mb-1">Barème sélectionné</div>
            <small id="preview-none" class="text-muted">Sélectionne une activité.</small>
            <div id="preview-data" style="display:none;">
              <small><strong>CA plafond :</strong> <span data-field="ca_ceiling"></span> €</small>
              <small><strong>TVA :</strong> <span data-field="tva_ceiling"></span> € (majoré : <span data-field="tva_ceiling_major"></span> €)</small>
              <small><strong>Social :</strong> <span data-field="social_rate"></span> %</small>
              <small><strong>IR libératoire :</strong> <span data-field="ir_rate"></span> %</small>
              <small><strong>CFP :</strong> <span data-field="cfp_rate"></span> %</small>
              <small><strong>Chambre :</strong> <span data-field="chamber"></span></small>
            </div>
          </div>

          <button class="btn btn-primary btn-sm w-100">Créer</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="col-lg-7">
      <h2 class="h6 mb-3">Résumé</h2>
      <table class="table table-sm">
        <thead>
          <tr>
            <th>Nom</th>
            <th>Activité</th>
            <th>CA</th>
            <th>TVA</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if ($micros): ?>
          <?php foreach ($micros as $m): ?>
            <tr>
              <td><?= h($m['name']) ?></td>
              <td><?= h($m['activity_code'] ?? '') ?></td>
              <td><?= $m['ca_ceiling']!==null ? number_format((float)$m['ca_ceiling'],0,',',' ') : '—' ?></td>
              <td><?= $m['tva_ceiling']!==null ? number_format((float)$m['tva_ceiling'],0,',',' ') : '—' ?></td>
              <td><a class="btn btn-sm btn-outline-primary" href="micro_view.php?id=<?= (int)$m['id'] ?>">Ouvrir</a></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="5" class="text-muted">Aucune micro.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const sel = document.getElementById('activity');
const previewNone = document.getElementById('preview-none');
const previewData = document.getElementById('preview-data');

if (sel) {
  sel.addEventListener('change', () => {
    const opt = sel.options[sel.selectedIndex];
    const js  = opt.getAttribute('data-json');
    if (!js) {
      previewNone.style.display = '';
      previewData.style.display = 'none';
      return;
    }
    let data;
    try { data = JSON.parse(js); } catch(e) {
      previewNone.style.display='';
      previewData.style.display='none';
      return;
    }
    previewNone.style.display='none';
    previewData.style.display='block';

    const map = {
      ca_ceiling: data.ca_ceiling,
      tva_ceiling: data.tva_ceiling,
      tva_ceiling_major: data.tva_ceiling_major,
      social_rate: (data.social_rate * 100).toFixed(2),
      ir_rate: data.ir_rate !== null ? (data.ir_rate * 100).toFixed(2) : '—',
      cfp_rate: data.cfp_rate !== null ? (data.cfp_rate * 100).toFixed(2) : '—',
      chamber: data.chamber_type
          ? data.chamber_type + (data.chamber_rate_default ? ' ' + (data.chamber_rate_default * 100).toFixed(3) + '%' : '')
          : '—'
    };

    previewData.querySelectorAll('[data-field]').forEach(el => {
      const k = el.getAttribute('data-field');
      el.textContent = map[k] ?? '';
    });
  });
}
</script>
</body>
</html>