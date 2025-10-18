<?php
declare(strict_types=1);

ini_set('display_errors','1'); error_reporting(E_ALL);

// 1) Autoload Composer: essaie plusieurs chemins depuis /test/tools/
$autoloadFound = false;
$candidates = [
  __DIR__.'/../../vendor/autoload.php',
  __DIR__.'/../vendor/autoload.php',
  dirname(__DIR__,3).'/vendor/autoload.php',
  dirname(__DIR__,2).'/vendor/autoload.php',
];
foreach ($candidates as $p) {
  if (is_file($p)) { require $p; $autoloadFound = true; break; }
}
if (!$autoloadFound) {
  header('Content-Type: text/plain; charset=UTF-8');
  http_response_code(500);
  echo "Autoload introuvable. Emplacements testés:\n- ".implode("\n- ", $candidates)."\n";
  exit;
}

use App\{Util, Database};

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

function h(string $s): string { return App\Util::h($s); }
function hasCol(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("PRAGMA table_info($table)"); $st->execute();
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (strcasecmp((string)$c['name'],$col)===0) return true;
  return false;
}

$accHasUser = hasCol($pdo,'accounts','user_id');
$txHasUser  = hasCol($pdo,'transactions','user_id');
$meHasUser  = hasCol($pdo,'micro_enterprises','user_id');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    Util::checkCsrf();
    $wipeMicro = isset($_POST['wipe_micro']);
    $pdo->beginTransaction();

    // Supprime transactions
    $sql = "DELETE FROM transactions";
    $p = [];
    if ($txHasUser) { $sql .= " WHERE user_id=:u"; $p[':u'] = $userId; }
    $pdo->prepare($sql)->execute($p);

    // Supprime comptes
    $sql = "DELETE FROM accounts";
    $p = [];
    if ($accHasUser) { $sql .= " WHERE user_id=:u"; $p[':u'] = $userId; }
    $pdo->prepare($sql)->execute($p);

    // Supprime micro (optionnel)
    if ($wipeMicro) {
      $sql = "DELETE FROM micro_enterprises";
      $p = [];
      if ($meHasUser) { $sql .= " WHERE user_id=:u"; $p[':u'] = $userId; }
      $pdo->prepare($sql)->execute($p);
    }

    $pdo->commit();
    Util::addFlash('success','Données supprimées: comptes et transactions'.($wipeMicro?' + micro':'').'.');
    Util::redirect('../accounts.php');
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    Util::addFlash('danger','Suppression échouée: '.$e->getMessage());
    Util::redirect('../accounts.php'); exit;
  }
}

// UI minimale
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Réinitialiser mes données</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="container py-3">
  <h1 class="h4 mb-3">Réinitialiser mes données</h1>
  <div class="alert alert-warning">
    Cette opération supprime vos comptes et transactions. Optionnel: micro‑entreprise. Action irréversible.
  </div>
  <form method="post" class="d-flex gap-2 align-items-center">
    <?= App\Util::csrfInput() ?>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="wm" name="wipe_micro">
      <label class="form-check-label" for="wm">Supprimer aussi la micro‑entreprise</label>
    </div>
    <button class="btn btn-danger">Supprimer</button>
    <a class="btn btn-outline-secondary" href="../accounts.php">Annuler</a>
  </form>
</body>
</html>