<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/bootstrap.php';

use App\{Util, Database};

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

// Helpers
function h(string $s): string { return App\Util::h($s); }
function hasTable(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
  $st->execute([':t'=>$table]);
  return (bool)$st->fetchColumn();
}
function hasCol(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("PRAGMA table_info($table)");
  $st->execute();
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    if (strcasecmp((string)$c['name'], $col) === 0) return true;
  }
  return false;
}
function ensureCol(PDO $pdo, string $table, string $col, string $type): void {
  if (!hasCol($pdo, $table, $col)) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
}

// Charge barèmes
$cfgPath = __DIR__.'/../config/micro_activities.php';
if (!is_file($cfgPath)) {
  include __DIR__.'/_nav.php';
  echo '<div class="container py-3"><div class="alert alert-danger">Fichier config/micro_activities.php introuvable.</div></div>';
  exit;
}
$activities = require $cfgPath;

// Pré-requis table
if (!hasTable($pdo, 'micro_enterprises')) {
  include __DIR__.'/_nav.php';
  echo '<div class="container py-3"><div class="alert alert-warning">Table micro_enterprises absente.</div></div>';
  exit;
}
foreach ([
  ['user_id','INTEGER'],
  ['activity_code','TEXT'],
  ['versement_liberatoire','INTEGER'],
  ['ca_ceiling','REAL'],
  ['vat_ceiling','REAL'],
  ['vat_ceiling_major','REAL'],
  ['social_contrib_rate','REAL'],
  ['income_tax_rate','REAL'],
  ['cfp_rate','REAL'],
  ['cma_rate','REAL'],
] as [$c,$t]) ensureCol($pdo, 'micro_enterprises', $c, $t);

// Récupère micros de l'utilisateur (une seule normalement)
$microHasUser = hasCol($pdo, 'micro_enterprises', 'user_id');
$sql = "SELECT * FROM micro_enterprises";
$p = [];
if ($microHasUser) { $sql .= " WHERE user_id = :u"; $p[':u'] = $userId; }
$st = $pdo->prepare($sql);
$st->execute($p);
$micros = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$report = [];
$updatedCount = 0;

$pdo->beginTransaction();
try {
  foreach ($micros as $m) {
    $mid  = (int)$m['id'];
    $code = (string)($m['activity_code'] ?? '');
    if ($code === '' || !isset($activities[$code])) {
      $report[] = [
        'micro_id'=>$mid, 'activity'=>$code ?: '—',
        'status'=>'ignored', 'reason'=>"Activité inconnue ou non définie"
      ];
      continue;
    }
    $def = $activities[$code];

    // Valeurs attendues d'après le fichier de barèmes
    $expected = [
      'ca_ceiling'          => (float)($def['ceilings']['ca'] ?? 0),
      'vat_ceiling'         => (float)($def['ceilings']['vat'] ?? 0),
      'vat_ceiling_major'   => (float)($def['ceilings']['vat_major'] ?? 0),
      'social_contrib_rate' => (float)($def['rates']['social'] ?? 0),
      // Impôt libératoire: seulement si versement_liberatoire == 1, sinon 0
      'income_tax_rate'     => ((int)($m['versement_liberatoire'] ?? 0) === 1)
                                ? (float)($def['rates']['income_tax'] ?? 0)
                                : 0.0,
      'cfp_rate'            => (float)($def['rates']['cfp'] ?? 0),
      'cma_rate'            => (float)($def['rates']['cma'] ?? 0),
    ];

    // Différences
    $diffs = [];
    foreach ($expected as $k => $v) {
      $cur = (float)($m[$k] ?? 0);
      // Tolérance minuscule pour éviter faux positifs (flottants)
      if (abs($cur - $v) > 1e-9) {
        $diffs[$k] = ['from'=>$cur, 'to'=>$v];
      }
    }

    if ($diffs) {
      // Applique les barèmes attendus
      $pdo->prepare("
        UPDATE micro_enterprises SET
          ca_ceiling=:ca,
          vat_ceiling=:vat,
          vat_ceiling_major=:vatm,
          social_contrib_rate=:rs,
          income_tax_rate=:ri,
          cfp_rate=:rcfp,
          cma_rate=:rcma
        WHERE id = :id
      ")->execute([
        ':ca'=>$expected['ca_ceiling'],
        ':vat'=>$expected['vat_ceiling'],
        ':vatm'=>$expected['vat_ceiling_major'],
        ':rs'=>$expected['social_contrib_rate'],
        ':ri'=>$expected['income_tax_rate'],
        ':rcfp'=>$expected['cfp_rate'],
        ':rcma'=>$expected['cma_rate'],
        ':id'=>$mid,
      ]);
      $updatedCount++;
      $report[] = [
        'micro_id'=>$mid,
        'activity'=>$def['label'] ?? $code,
        'status'=>'updated',
        'diffs'=>$diffs
      ];
    } else {
      $report[] = [
        'micro_id'=>$mid,
        'activity'=>$def['label'] ?? $code,
        'status'=>'ok'
      ];
    }
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  include __DIR__.'/_nav.php';
  echo '<div class="container py-3"><div class="alert alert-danger">'.h($e->getMessage()).'</div></div>';
  exit;
}

// Rendu
include __DIR__.'/_nav.php';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Vérification Micro — Synchronisation barèmes</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Courier New",monospace}</style>
</head>
<body>
<div class="container py-3">
  <div class="card shadow-sm">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <strong>Synchronisation des barèmes micro (config → base)</strong>
      <span class="text-muted small">config/micro_activities.php</span>
    </div>
    <div class="card-body">
      <p class="mb-2">
        Résultat: <?= (int)count($report) ?> micro(s) vérifiée(s),
        <strong><?= (int)$updatedCount ?></strong> mise(s) à jour.
      </p>

      <?php if (!$report): ?>
        <div class="alert alert-info mb-0">Aucune micro‑entreprise pour votre session.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Micro ID</th>
                <th>Activité</th>
                <th>État</th>
                <th>Détails des corrections</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($report as $row): ?>
                <tr>
                  <td class="mono"><?= (int)$row['micro_id'] ?></td>
                  <td><?= h((string)$row['activity']) ?></td>
                  <td>
                    <?php if ($row['status']==='updated'): ?>
                      <span class="badge bg-warning text-dark">Mis à jour</span>
                    <?php elseif ($row['status']==='ok'): ?>
                      <span class="badge bg-success">OK</span>
                    <?php else: ?>
                      <span class="badge bg-secondary">Ignoré</span>
                      <div class="small text-muted"><?= h((string)($row['reason'] ?? '')) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($row['diffs'])): ?>
                      <div class="small mono">
                        <?php foreach ($row['diffs'] as $k=>$d): ?>
                          <div><?= h($k) ?>: <?= h((string)$d['from']) ?> → <strong><?= h((string)$d['to']) ?></strong></div>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="alert alert-info mt-3 mb-0">
          Astuce: si vous modifiez le fichier des barèmes puis revenez sur la page <strong>Micro</strong>,
          relancez d’abord cette synchronisation pour que les taux/plafonds (dont <strong>CMA</strong>) soient recopiés en base.
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>