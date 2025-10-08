<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\{Util, Database};

Util::startSession();
$db  = new Database();
$pdo = $db->pdo();
Util::requireAdmin($pdo);

$error = null;
$mode  = $_GET['mode'] ?? 'list';
$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;

function h(string $v): string { return App\Util::h($v); }

/**
 * Convertit une chaîne utilisateur en float ou null (tolère virgule).
 */
function fOrNull(?string $s): ?float {
    if ($s === null) return null;
    $s = trim(str_replace(["\u{00A0}", ' '], '', $s));
    if ($s === '') return null;
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) return null;
    return (float)$s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'create' || $action === 'update') {
            $data = [
                'code'  => trim($_POST['code'] ?? ''),
                'label' => trim($_POST['label'] ?? ''),
                'family'=> trim($_POST['family'] ?? ''),
                'social_rate' => fOrNull($_POST['social_rate'] ?? '') ?? 0.0,
                'ir_rate'     => fOrNull($_POST['ir_rate'] ?? ''),
                'cfp_rate'    => fOrNull($_POST['cfp_rate'] ?? ''),
                'chamber_type'=> trim($_POST['chamber_type'] ?? '') ?: null,
                'chamber_rate_default' => fOrNull($_POST['chamber_rate_default'] ?? ''),
                'chamber_rate_alsace'  => fOrNull($_POST['chamber_rate_alsace'] ?? ''),
                'chamber_rate_moselle' => fOrNull($_POST['chamber_rate_moselle'] ?? ''),
                'ca_ceiling'        => fOrNull($_POST['ca_ceiling'] ?? '') ?? 0,
                'tva_ceiling'       => fOrNull($_POST['tva_ceiling'] ?? '') ?? 0,
                'tva_ceiling_major' => fOrNull($_POST['tva_ceiling_major'] ?? '') ?? 0,
                'tva_alert_threshold' => fOrNull($_POST['tva_alert_threshold'] ?? '') ?? 0.50,
            ];

            if ($data['code'] === '' || $data['label'] === '' || $data['family'] === '') {
                throw new RuntimeException('Champs requis manquants.');
            }

            // Vérifications simples
            foreach (['social_rate','ir_rate','cfp_rate','chamber_rate_default','chamber_rate_alsace','chamber_rate_moselle'] as $k) {
                if ($data[$k] !== null && $data[$k] < 0) {
                    throw new RuntimeException("Taux négatif interdit ($k).");
                }
            }

            if ($action === 'create') {
                $sql = "INSERT INTO micro_activity_rates
                  (code,label,family,social_rate,ir_rate,cfp_rate,chamber_type,
                   chamber_rate_default,chamber_rate_alsace,chamber_rate_moselle,
                   ca_ceiling,tva_ceiling,tva_ceiling_major,tva_alert_threshold,created_at)
                  VALUES
                  (:code,:label,:family,:sr,:ir,:cfp,:ch_type,
                   :ch_def,:ch_al,:ch_mo,
                   :ca,:tva,:tvaM,:tvaThr,datetime('now'))";
            } else {
                $sql = "UPDATE micro_activity_rates SET
                   code=:code,label=:label,family=:family,
                   social_rate=:sr,ir_rate=:ir,cfp_rate=:cfp,
                   chamber_type=:ch_type,
                   chamber_rate_default=:ch_def,
                   chamber_rate_alsace=:ch_al,
                   chamber_rate_moselle=:ch_mo,
                   ca_ceiling=:ca,tva_ceiling=:tva,tva_ceiling_major=:tvaM,
                   tva_alert_threshold=:tvaThr
                   WHERE id=:id";
            }

            $st = $pdo->prepare($sql);
            if ($action === 'update') {
                $st->bindValue(':id', (int)($_POST['id'] ?? 0), PDO::PARAM_INT);
            }
            $st->execute([
                ':code'   => $data['code'],
                ':label'  => $data['label'],
                ':family' => $data['family'],
                ':sr'     => $data['social_rate'],
                ':ir'     => $data['ir_rate'],
                ':cfp'    => $data['cfp_rate'],
                ':ch_type'=> $data['chamber_type'],
                ':ch_def' => $data['chamber_rate_default'],
                ':ch_al'  => $data['chamber_rate_alsace'],
                ':ch_mo'  => $data['chamber_rate_moselle'],
                ':ca'     => $data['ca_ceiling'],
                ':tva'    => $data['tva_ceiling'],
                ':tvaM'   => $data['tva_ceiling_major'],
                ':tvaThr' => $data['tva_alert_threshold'],
            ]);

            Util::addFlash('success', $action === 'create' ? 'Barème créé' : 'Barème mis à jour');
            Util::redirect('admin_activity_simple.php');

        } elseif ($action === 'delete') {
            $delId = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM micro_activity_rates WHERE id=:id")
                ->execute([':id'=>$delId]);
            Util::addFlash('success','Barème supprimé.');
            Util::redirect('admin_activity_simple.php');
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$current = null;
if ($mode === 'edit') {
    $st = $pdo->prepare("SELECT * FROM micro_activity_rates WHERE id=:id");
    $st->execute([':id'=>$id]);
    $current = $st->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        Util::addFlash('danger','Barème introuvable.');
        Util::redirect('admin_activity_simple.php');
    }
}

$rates = $pdo->query("SELECT * FROM micro_activity_rates ORDER BY code")->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Admin - Barèmes (simple)</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
small.hint{font-size:.7rem;color:#666;}
</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Administration – Barèmes (mode simple)</h1>
    <div>
      <a href="index.php" class="btn btn-sm btn-secondary">Accueil</a>
      <a href="admin_activity_simple.php" class="btn btn-sm btn-outline-primary">Liste</a>
      <a href="admin_activity_simple.php?mode=create" class="btn btn-sm btn-primary">Nouveau</a>
    </div>
  </div>

  <?php foreach (Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger py-2"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($mode === 'list'): ?>
    <div class="card shadow-sm">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm table-striped mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Code</th><th>Label</th><th>CA</th><th>TVA</th><th>TVA maj</th>
                <th>Social %</th><th>IR %</th><th>CFP %</th><th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rates as $r): ?>
              <tr>
                <td><?= h($r['code']) ?></td>
                <td><?= h($r['label']) ?></td>
                <td><?= number_format((float)$r['ca_ceiling'],0,',',' ') ?></td>
                <td><?= number_format((float)$r['tva_ceiling'],0,',',' ') ?></td>
                <td><?= number_format((float)$r['tva_ceiling_major'],0,',',' ') ?></td>
                <td><?= number_format($r['social_rate']*100,2,',',' ') ?></td>
                <td><?= $r['ir_rate']!==null? number_format($r['ir_rate']*100,2,',',' ') : '—' ?></td>
                <td><?= $r['cfp_rate']!==null? number_format($r['cfp_rate']*100,2,',',' ') : '—' ?></td>
                <td>
                  <a class="btn btn-sm btn-outline-primary" href="?mode=edit&id=<?= (int)$r['id'] ?>">Éditer</a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ?');">
                    <?= Util::csrfInput() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">X</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; if (!$rates): ?>
              <tr><td colspan="9" class="text-muted">Aucun barème.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php else:
    $isEdit = ($mode === 'edit');
    $v = $current ?? [
      'code'=>'','label'=>'','family'=>'',
      'social_rate'=>'','ir_rate'=>'','cfp_rate'=>'',
      'chamber_type'=>'','chamber_rate_default'=>'',
      'chamber_rate_alsace'=>'','chamber_rate_moselle'=>'',
      'ca_ceiling'=>'','tva_ceiling'=>'','tva_ceiling_major'=>'','tva_alert_threshold'=>'0.50'
    ];
  ?>
    <div class="card shadow-sm">
      <div class="card-header py-2"><strong><?= $isEdit ? 'Éditer' : 'Nouveau' ?> barème</strong></div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <?= Util::csrfInput() ?>
          <input type="hidden" name="action" value="<?= $isEdit ? 'update':'create' ?>">
          <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
          <?php endif; ?>

          <div class="col-md-2">
            <label class="form-label">Code</label>
            <input name="code" class="form-control form-control-sm" required value="<?= h($v['code']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Label</label>
            <input name="label" class="form-control form-control-sm" required value="<?= h($v['label']) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Famille</label>
            <input name="family" class="form-control form-control-sm" required value="<?= h($v['family']) ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label">Social (fraction)</label>
            <input name="social_rate" type="number" step="0.0001" required class="form-control form-control-sm" value="<?= h($v['social_rate']) ?>">
            <small class="hint">0.212 = 21.2%</small>
          </div>
          <div class="col-md-3">
            <label class="form-label">IR (fraction)</label>
            <input name="ir_rate" type="number" step="0.0001" class="form-control form-control-sm" value="<?= h($v['ir_rate']) ?>">
          </div>
            <div class="col-md-3">
            <label class="form-label">CFP (fraction)</label>
            <input name="cfp_rate" type="number" step="0.0001" class="form-control form-control-sm" value="<?= h($v['cfp_rate']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Type chambre</label>
            <input name="chamber_type" class="form-control form-control-sm" value="<?= h($v['chamber_type']) ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label">Ch. défaut</label>
            <input name="chamber_rate_default" type="number" step="0.0001" class="form-control form-control-sm" value="<?= h($v['chamber_rate_default']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Ch. Alsace</label>
            <input name="chamber_rate_alsace" type="number" step="0.0001" class="form-control form-control-sm" value="<?= h($v['chamber_rate_alsace']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Ch. Moselle</label>
            <input name="chamber_rate_moselle" type="number" step="0.0001" class="form-control form-control-sm" value="<?= h($v['chamber_rate_moselle']) ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label">CA plafond</label>
            <input name="ca_ceiling" type="number" step="1" required class="form-control form-control-sm" value="<?= h($v['ca_ceiling']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">TVA</label>
            <input name="tva_ceiling" type="number" step="1" required class="form-control form-control-sm" value="<?= h($v['tva_ceiling']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">TVA majoré</label>
            <input name="tva_ceiling_major" type="number" step="1" required class="form-control form-control-sm" value="<?= h($v['tva_ceiling_major']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Alerte TVA ratio</label>
            <input name="tva_alert_threshold" type="number" step="0.01" class="form-control form-control-sm" value="<?= h($v['tva_alert_threshold']) ?>">
            <small class="hint">0.50 = 50%</small>
          </div>

          <div class="col-12">
            <button class="btn btn-primary btn-sm"><?= $isEdit ? 'Mettre à jour':'Créer' ?></button>
            <a href="admin_activity_simple.php" class="btn btn-secondary btn-sm">Annuler</a>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>