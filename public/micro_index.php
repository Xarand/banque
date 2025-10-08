<?php declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database, MicroEnterpriseRepository, MicroActivityRepository};

Util::startSession();
Util::requireAuth();

$pdo  = (new Database())->pdo();
$repo = new MicroEnterpriseRepository($pdo);
$act  = new MicroActivityRepository($pdo);
$userId = Util::currentUserId();

$micros = $repo->listMicro($userId);
$hasMicro = !empty($micros);
$activities = $act->listAll();
$error=null;

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form'] ?? '')==='create') {
    if ($hasMicro) {
        Util::addFlash('warning','Une micro existe déjà.');
        Util::redirect('micro_view.php?id='.(int)$micros[0]['id']);
    }
    try {
        Util::checkCsrf();
        $name = trim($_POST['name'] ?? '');
        $activityCode = $_POST['activity_code'] ?: null;
        if ($name==='') throw new RuntimeException("Nom requis.");
        if (!$activityCode) throw new RuntimeException("Activité requise.");
        $freq = $_POST['contributions_frequency'] ?? 'quarterly';
        $ir   = !empty($_POST['ir_liberatoire']) ? 1 : 0;
        $id = $repo->createMicro($userId,$name,null,null,$activityCode,$freq,$ir);
        Util::addFlash('success','Micro créée.');
        Util::redirect('micro_view.php?id='.$id);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function h($v){ return App\Util::h((string)$v); }
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Micro</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.rate-preview small{display:block;line-height:1.2;}</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <?php foreach(Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>
  <div class="row g-4">
    <div class="col-lg-5">
      <h1 class="h5 mb-3">Micro-entreprise</h1>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
      <?php if ($hasMicro): ?>
        <div class="alert alert-info py-2">Une micro existe déjà.</div>
        <a class="btn btn-primary btn-sm" href="micro_view.php?id=<?= (int)$micros[0]['id'] ?>">Ouvrir</a>
      <?php else: ?>
        <form method="post" class="card p-3 shadow-sm">
          <?= Util::csrfInput() ?>
          <input type="hidden" name="form" value="create">
          <div class="mb-2">
            <label class="form-label">Nom</label>
            <input name="name" class="form-control form-control-sm" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Activité</label>
            <select name="activity_code" class="form-select form-select-sm" id="activity" required>
              <option value="">-- Choisir --</option>
              <?php foreach($activities as $a): ?>
                <option value="<?= h($a['code']) ?>"
                  data-json='<?= h(json_encode($a, JSON_THROW_ON_ERROR)) ?>'>
                  <?= h($a['code'].' - '.$a['label']) ?>
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
          <div class="border rounded p-2 mb-3 bg-white rate-preview">
            <div class="fw-bold mb-1">Barème sélectionné</div>
            <small id="preview-none" class="text-muted">Sélectionne une activité.</small>
            <div id="preview-data" style="display:none;">
              <small><strong>Plafond CA:</strong> <span data-field="ca_ceiling"></span> €</small>
              <small><strong>TVA:</strong> <span data-field="tva_ceiling"></span> € (majoré <span data-field="tva_ceiling_major"></span> €)</small>
              <small><strong>Social:</strong> <span data-field="social_rate"></span>%</small>
              <small><strong>IR:</strong> <span data-field="ir_rate"></span>%</small>
              <small><strong>CFP:</strong> <span data-field="cfp_rate"></span>%</small>
              <small><strong>Chambre:</strong> <span data-field="chamber"></span></small>
            </div>
          </div>
          <button class="btn btn-primary btn-sm w-100">Créer</button>
        </form>
      <?php endif; ?>
    </div>
    <div class="col-lg-7">
      <h2 class="h6 mb-3">Résumé</h2>
      <table class="table table-sm">
        <thead><tr><th>Nom</th><th>Activité</th><th>CA</th><th>TVA</th><th></th></tr></thead>
        <tbody>
          <?php if($micros): foreach($micros as $m): ?>
            <tr>
              <td><?= h($m['name']) ?></td>
              <td><?= h($m['activity_code'] ?? '') ?></td>
              <td><?= $m['ca_ceiling']!==null? number_format((float)$m['ca_ceiling'],0,',',' ') : '—' ?></td>
              <td><?= $m['tva_ceiling']!==null? number_format((float)$m['tva_ceiling'],0,',',' ') : '—' ?></td>
              <td><a class="btn btn-sm btn-outline-primary" href="micro_view.php?id=<?= (int)$m['id'] ?>">Ouvrir</a></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5" class="text-muted">Aucune micro.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
const sel = document.getElementById('activity');
const pn  = document.getElementById('preview-none');
const pd  = document.getElementById('preview-data');
sel?.addEventListener('change', ()=>{
  const opt = sel.options[sel.selectedIndex];
  const js = opt.getAttribute('data-json');
  if(!js){ pn.style.display=''; pd.style.display='none'; return; }
  const d = JSON.parse(js);
  pn.style.display='none';
  pd.style.display='block';
  const map = {
    ca_ceiling:d.ca_ceiling,
    tva_ceiling:d.tva_ceiling,
    tva_ceiling_major:d.tva_ceiling_major,
    social_rate:(d.social_rate*100).toFixed(2),
    ir_rate:d.ir_rate!==null?(d.ir_rate*100).toFixed(2):'—',
    cfp_rate:d.cfp_rate!==null?(d.cfp_rate*100).toFixed(2):'—',
    chamber:d.chamber_type? d.chamber_type + (d.chamber_rate_default ? ' '+(d.chamber_rate_default*100).toFixed(3)+'%' : '') : '—'
  };
  [...pd.querySelectorAll('[data-field]')].forEach(el=>{
    const k = el.getAttribute('data-field');
    el.textContent = map[k] ?? '';
  });
});
</script>
</body>
</html>