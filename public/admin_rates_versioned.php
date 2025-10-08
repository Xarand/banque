<?php declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database, VersionedActivityRateRepository};

Util::startSession();
$db  = new Database();
$pdo = $db->pdo();
Util::requireAdmin($pdo);

$repo = new VersionedActivityRateRepository($pdo);

$mode = $_GET['mode'] ?? 'list';
$error = null;
$msg   = null;

function h($v){ return App\Util::h((string)$v); }
function fOrNull(string $s): ?float {
    $s = trim(str_replace([' ', "\u{00A0}"],'', $s));
    if ($s==='') return null;
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^-?\d+(\.\d+)?$/',$s)) return null;
    return (float)$s;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        Util::checkCsrf();
        $action = $_POST['action'] ?? '';
        if (in_array($action,['draft_create','draft_update'],true)) {
            $data = [
                'code'=>trim($_POST['code']),
                'label'=>trim($_POST['label']),
                'family'=>trim($_POST['family']),
                'social_rate'=> (float)$_POST['social_rate'],
                'ir_rate'=> ($_POST['ir_rate']==='' ? null : (float)$_POST['ir_rate']),
                'cfp_rate'=> ($_POST['cfp_rate']==='' ? null : (float)$_POST['cfp_rate']),
                'chamber_type'=> trim($_POST['chamber_type'] ?? ''),
                'chamber_rate_default'=> ($_POST['chamber_rate_default']===''? null:(float)$_POST['chamber_rate_default']),
                'chamber_rate_alsace'=> ($_POST['chamber_rate_alsace']===''? null:(float)$_POST['chamber_rate_alsace']),
                'chamber_rate_moselle'=> ($_POST['chamber_rate_moselle']===''? null:(float)$_POST['chamber_rate_moselle']),
                'ca_ceiling'=> (float)$_POST['ca_ceiling'],
                'tva_ceiling'=> (float)$_POST['tva_ceiling'],
                'tva_ceiling_major'=> (float)$_POST['tva_ceiling_major'],
                'tva_alert_threshold'=> (float)($_POST['tva_alert_threshold'] ?? 0.50),
            ];
            if ($action==='draft_create') {
                $repo->createDraft($data);
                Util::addFlash('success','Brouillon créé.');
            } else {
                $id = (int)$_POST['id'];
                $repo->updateDraft($id,$data);
                Util::addFlash('success','Brouillon mis à jour.');
            }
            Util::redirect('admin_rates_versioned.php');
        } elseif ($action==='draft_delete') {
            $repo->deleteDraft((int)$_POST['id']);
            Util::addFlash('success','Brouillon supprimé.');
            Util::redirect('admin_rates_versioned.php');
        } elseif ($action==='apply_draft_set') {
            $note = trim($_POST['note'] ?? '');
            $repo->applyDraftSet($note ?: null);
            $updated = $repo->propagateCeilingsToMicro();
            Util::addFlash('success',"Barèmes appliqués. Micro mises à jour: $updated.");
            Util::redirect('admin_rates_versioned.php');
        } elseif ($action==='rollback_history') {
            $repo->rollbackToHistory((int)$_POST['history_id']);
            Util::addFlash('warning','Rollback effectif (barèmes actifs restaurés).');
            Util::redirect('admin_rates_versioned.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$active = $repo->listActive();
$draft  = $repo->listDraft();
$history = $repo->listHistory(15);

$editDraft = null;
if ($mode==='edit_draft') {
    $editId = (int)($_GET['id'] ?? 0);
    $editDraft = $repo->getDraftById($editId);
    if (!$editDraft) {
        Util::addFlash('danger','Brouillon introuvable.');
        Util::redirect('admin_rates_versioned.php');
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Admin - Barèmes versionnés</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
table td, table th {white-space: nowrap;}
.diff-added {background:#e6ffe6;}
.diff-changed {background:#fff4e0;}
</style>
</head>
<body class="bg-light">
<div class="container-fluid py-3">
  <div class="d-flex justify-content-between mb-3">
    <h1 class="h5 mb-0">Administration – Barèmes (brouillons & versions)</h1>
    <div>
      <a href="index.php" class="btn btn-sm btn-secondary">Retour</a>
      <a href="admin_rates_versioned.php" class="btn btn-sm btn-outline-primary">Vue générale</a>
      <a href="admin_rates_versioned.php?mode=create_draft" class="btn btn-sm btn-primary">Nouveau brouillon</a>
    </div>
  </div>

  <?php foreach (App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>
  <?php if($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>

  <?php if ($mode==='list'): ?>
  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Barèmes actifs</strong></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead class="table-light">
                <tr><th>Code</th><th>CA</th><th>TVA</th><th>Social%</th><th>IR%</th><th>CFP%</th></tr>
              </thead>
              <tbody>
              <?php foreach($active as $a): ?>
                <tr>
                  <td><?= h($a['code']) ?></td>
                  <td><?= number_format((float)$a['ca_ceiling'],0,',',' ') ?></td>
                  <td><?= number_format((float)$a['tva_ceiling'],0,',',' ') ?></td>
                  <td><?= number_format($a['social_rate']*100,2,',',' ') ?></td>
                  <td><?= $a['ir_rate']!==null? number_format($a['ir_rate']*100,2,',',' '):'—' ?></td>
                  <td><?= $a['cfp_rate']!==null? number_format($a['cfp_rate']*100,2,',',' '):'—' ?></td>
                </tr>
              <?php endforeach; if(!$active): ?>
                <tr><td colspan="6" class="text-muted">Aucun actif.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card shadow-sm mt-3">
        <div class="card-header py-2"><strong>Historique (snapshots)</strong></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>ID</th><th>Appliqué</th><th>Note</th><th>Actions</th></tr></thead>
              <tbody>
              <?php foreach($history as $h): ?>
                <tr>
                  <td><?= (int)$h['id'] ?></td>
                  <td><?= h($h['applied_at']) ?></td>
                  <td><?= h($h['note'] ?? '') ?></td>
                  <td>
                    <form method="post" class="d-inline" onsubmit="return confirm('Rollback vers ce snapshot ?');">
                      <?= Util::csrfInput() ?>
                      <input type="hidden" name="action" value="rollback_history">
                      <input type="hidden" name="history_id" value="<?= (int)$h['id'] ?>">
                      <button class="btn btn-sm btn-outline-warning">Rollback</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; if (!$history): ?>
                <tr><td colspan="4" class="text-muted">Pas d’historique.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <strong>Brouillons</strong>
          <?php if($draft): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Appliquer tous les brouillons comme actifs ?');">
            <?= Util::csrfInput() ?>
            <input type="hidden" name="action" value="apply_draft_set">
            <input type="text" name="note" class="form-control form-control-sm d-inline-block" style="width:200px;" placeholder="Note (optionnel)">
            <button class="btn btn-sm btn-success">Appliquer</button>
          </form>
          <?php endif; ?>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead class="table-light">
                <tr><th>Code</th><th>CA</th><th>TVA</th><th>Social%</th><th>Δ</th><th></th></tr>
              </thead>
              <tbody>
              <?php
              // Préparation comparaison
              $activeByCode = [];
              foreach($active as $a) $activeByCode[$a['code']] = $a;
              foreach($draft as $d):
                $diff = '';
                if (isset($activeByCode[$d['code']])) {
                    $a = $activeByCode[$d['code']];
                    if ((float)$a['social_rate'] !== (float)$d['social_rate'] ||
                        (float)$a['ca_ceiling'] !== (float)$d['ca_ceiling'] ||
                        (float)$a['tva_ceiling'] !== (float)$d['tva_ceiling']) {
                        $diff = 'diff-changed';
                    }
                } else {
                    $diff = 'diff-added';
                }
              ?>
                <tr class="<?= $diff ?>">
                  <td><?= h($d['code']) ?></td>
                  <td><?= number_format((float)$d['ca_ceiling'],0,',',' ') ?></td>
                  <td><?= number_format((float)$d['tva_ceiling'],0,',',' ') ?></td>
                  <td><?= number_format($d['social_rate']*100,2,',',' ') ?></td>
                  <td><?= $diff ? ($diff==='diff-added'?'Nouveau':'Modifié') : '' ?></td>
                  <td>
                    <a class="btn btn-sm btn-outline-primary" href="admin_rates_versioned.php?mode=edit_draft&id=<?= (int)$d['id'] ?>">Éditer</a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ce brouillon ?');">
                      <?= Util::csrfInput() ?>
                      <input type="hidden" name="action" value="draft_delete">
                      <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">X</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; if(!$draft): ?>
                <tr><td colspan="6" class="text-muted">Aucun brouillon.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="mt-2 small text-muted">
        Légende : fond vert = barème nouveau ; fond orange = barème modifié vs actif.
      </div>
    </div>
  </div>

  <?php elseif ($mode==='create_draft' || $mode==='edit_draft'):
    $isEdit = ($mode==='edit_draft');
    $val = $editDraft ?? [
      'code'=>'','label'=>'','family'=>'',
      'social_rate'=>'','ir_rate'=>'','cfp_rate'=>'',
      'chamber_type'=>'','chamber_rate_default'=>'',
      'chamber_rate_alsace'=>'','chamber_rate_moselle'=>'',
      'ca_ceiling'=>'','tva_ceiling'=>'','tva_ceiling_major'=>'','tva_alert_threshold'=>'0.50'
    ];
  ?>
  <div class="card shadow-sm">
    <div class="card-header py-2">
      <strong><?= $isEdit ? 'Éditer brouillon' : 'Nouveau brouillon' ?></strong>
    </div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <?= Util::csrfInput() ?>
        <input type="hidden" name="action" value="<?= $isEdit ? 'draft_update' : 'draft_create' ?>">
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= (int)$val['id'] ?>">
        <?php endif; ?>
        <div class="col-md-2">
          <label class="form-label">Code</label>
          <input name="code" class="form-control form-control-sm" required value="<?= h($val['code']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Label</label>
          <input name="label" class="form-control form-control-sm" required value="<?= h($val['label']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Famille</label>
            <input name="family" class="form-control form-control-sm" required value="<?= h($val['family']) ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Social (fraction)</label>
          <input name="social_rate" type="number" step="0.0001" class="form-control form-control-sm" required value="<?= h($val['social_rate']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">IR (fraction)</label>
          <input name="ir_rate" type="number" step="0.0001" class="form-control form-control-sm" value="<?= h($val['ir_rate']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">CFP (fraction)</label>
          <input name="cfp_rate" type="number" step="0.0001" class="form-control form-control-sm" value="<?= h($val['cfp_rate']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Type chambre</label>
          <input name="chamber_type" class="form-control form-control-sm" value="<?= h($val['chamber_type']) ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Chambre déf.</label>
          <input name="chamber_rate_default" type="number" step="0.0001" class="form-control form-control-sm" value="<?= h($val['chamber_rate_default']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Chambre Alsace</label>
          <input name="chamber_rate_alsace" type="number" step="0.0001" class="form-control form-control-sm" value="<?= h($val['chamber_rate_alsace']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Chambre Moselle</label>
          <input name="chamber_rate_moselle" type="number" step="0.0001" class="form-control form-control-sm" value="<?= h($val['chamber_rate_moselle']) ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">CA plafond</label>
          <input name="ca_ceiling" type="number" step="1" class="form-control form-control-sm" required value="<?= h($val['ca_ceiling']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">TVA plafond</label>
          <input name="tva_ceiling" type="number" step="1" class="form-control form-control-sm" required value="<?= h($val['tva_ceiling']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">TVA majoré</label>
          <input name="tva_ceiling_major" type="number" step="1" class="form-control form-control-sm" required value="<?= h($val['tva_ceiling_major']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Alerte TVA ratio</label>
          <input name="tva_alert_threshold" type="number" step="0.01" class="form-control form-control-sm" value="<?= h($val['tva_alert_threshold']) ?>">
        </div>

        <div class="col-12">
          <button class="btn btn-primary btn-sm"><?= $isEdit ? 'Mettre à jour' : 'Créer' ?></button>
          <a href="admin_rates_versioned.php" class="btn btn-secondary btn-sm">Annuler</a>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>