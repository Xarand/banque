<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database, MicroEnterpriseRepository};

ini_set('display_errors','1'); // désactiver en prod si besoin
error_reporting(E_ALL);

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

function h(string $s): string { return App\Util::h($s); }
function fmt(float $n): string { return number_format($n, 2, ',', ' '); }

function hasCol(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("PRAGMA table_info($table)");
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (strcasecmp((string)$c['name'], $col) === 0) return true;
    }
    return false;
}
function ensureColumn(PDO $pdo, string $table, string $col, string $typeSql): void {
    if (!hasCol($pdo, $table, $col)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $col $typeSql");
    }
}

$accHasUser  = hasCol($pdo, 'accounts', 'user_id');
$accHasMicro = hasCol($pdo, 'accounts', 'micro_enterprise_id');
$accHasCAts  = hasCol($pdo, 'accounts', 'created_at');
$trxHasUser  = hasCol($pdo, 'transactions', 'user_id');

// Colonnes attendues côté micro_enterprises pour stocker plafonds/taux
$microColsToEnsure = [
    ['activity_code',    'TEXT'],
    ['ca_ceiling',       'REAL'],
    ['vat_ceiling',      'REAL'],
    ['vat_ceiling_major','REAL'],
    ['social_contrib_rate','REAL'],
    ['income_tax_rate',  'REAL'],
    ['cfp_rate',         'REAL'],
    ['cma_rate',         'REAL'],
];
foreach ($microColsToEnsure as [$c,$t]) ensureColumn($pdo, 'micro_enterprises', $c, $t);

$activities = require __DIR__.'/../config/micro_activities.php';

// Micro de l'utilisateur (pour rattacher un compte micro + activité)
$microRow = null;
try {
    if (class_exists(MicroEnterpriseRepository::class)) {
        $microRepo = new MicroEnterpriseRepository($pdo);
        $list = $microRepo->listMicro($userId);
        $microRow = $list ? $list[0] : null;
    } else {
        $st = $pdo->prepare("SELECT * FROM micro_enterprises WHERE user_id = :u LIMIT 1");
        $st->execute([':u'=>$userId]);
        $microRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    $microRow = null;
}

// Construit les options d'activité à afficher
$activityOptions = [];
foreach ($activities as $code => $def) {
    $activityOptions[$code] = (string)$def['label'];
}
// Si la micro a un code inconnu, l’ajouter pour ne pas le perdre
$existingCode = (string)($microRow['activity_code'] ?? '');
if ($existingCode !== '' && !isset($activityOptions[$existingCode])) {
    $activityOptions[$existingCode] = 'Activité existante: '.ucfirst($existingCode);
}

// Helpers de sécurité
function ensureAccountOwned(PDO $pdo, int $accId, int $userId, bool $accHasUser): array {
    $sqlAcc = "SELECT * FROM accounts WHERE id = :id";
    $params = [ ':id' => $accId ];
    if ($accHasUser) { $sqlAcc .= " AND user_id = :u"; $params[':u'] = $userId; }
    $st = $pdo->prepare($sqlAcc);
    $st->execute($params);
    $acc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$acc) throw new RuntimeException("Compte introuvable.");
    return $acc;
}
function countAccountTransactions(PDO $pdo, int $accId, int $userId, bool $trxHasUser): int {
    $sql = "SELECT COUNT(*) FROM transactions WHERE account_id = :id";
    $params = [':id'=>$accId];
    if ($trxHasUser) { $sql .= " AND user_id = :u"; $params[':u'] = $userId; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

// Actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();
        $action = (string)($_POST['action'] ?? '');

        // Créer un compte
        if ($action === 'create_account') {
            $name = trim((string)($_POST['name'] ?? ''));
            $kind = (string)($_POST['kind'] ?? 'personal'); // personal|micro
            $activitySel = trim((string)($_POST['activity_code'] ?? ''));
            if ($name === '') throw new RuntimeException("Le nom du compte est requis.");

            $microId = null;
            if ($kind === 'micro') {
                if (!$accHasMicro) throw new RuntimeException("Le schéma ne supporte pas les comptes micro (colonne micro_enterprise_id absente).");
                if ($microRow === null) throw new RuntimeException("Aucune micro disponible pour rattacher ce compte.");
                $microId = (int)$microRow['id'];

                // Détermine la fiche d’activité
                $codeToUse = $activitySel !== '' ? $activitySel : ($existingCode !== '' ? $existingCode : 'service');
                $def = $activities[$codeToUse] ?? null;
                if ($def === null) {
                    throw new RuntimeException("Activité inconnue: $codeToUse");
                }

                // Met à jour la micro-entreprise avec l’activité et ses plafonds/taux
                $sqlUp = "
                  UPDATE micro_enterprises
                  SET
                    activity_code         = :ac,
                    ca_ceiling            = :ca,
                    vat_ceiling           = :vat,
                    vat_ceiling_major     = :vatm,
                    social_contrib_rate   = :rs,
                    income_tax_rate       = :ri,
                    cfp_rate              = :rcfp,
                    cma_rate              = :rcma
                  WHERE id = :mid
                ";
                $pdo->prepare($sqlUp)->execute([
                    ':ac'   => $codeToUse,
                    ':ca'   => (float)$def['ceilings']['ca'],
                    ':vat'  => (float)$def['ceilings']['vat'],
                    ':vatm' => (float)$def['ceilings']['vat_major'],
                    ':rs'   => (float)$def['rates']['social'],
                    ':ri'   => (float)$def['rates']['income_tax'],
                    ':rcfp' => (float)$def['rates']['cfp'],
                    ':rcma' => (float)$def['rates']['cma'],
                    ':mid'  => $microId,
                ]);
            }

            $cols = ['name']; $vals = [':name']; $bind = [':name'=>$name];
            if ($accHasUser)  { $cols[]='user_id';               $vals[]=':uid'; $bind[':uid']=$userId; }
            if ($accHasMicro) { $cols[]='micro_enterprise_id';   $vals[]=':mid'; $bind[':mid']=$microId; }
            if ($accHasCAts)  { $cols[]='created_at';            $vals[]="datetime('now')"; }

            $sql = "INSERT INTO accounts(".implode(',', $cols).") VALUES (".implode(',', $vals).")";
            $pdo->prepare($sql)->execute($bind);

            Util::addFlash('success', "Compte créé.".($kind==='micro' ? " Activité et taux appliqués." : ""));
            Util::redirect('accounts.php');
        }

        // Renommer un compte
        if ($action === 'update_account') {
            $accId = (int)($_POST['account_id'] ?? 0);
            $name  = trim((string)($_POST['name'] ?? ''));
            if ($accId <= 0) throw new RuntimeException("Compte invalide.");
            if ($name === '') throw new RuntimeException("Le nom du compte est requis.");

            ensureAccountOwned($pdo, $accId, $userId, $accHasUser);

            $sql = "UPDATE accounts SET name = :n WHERE id = :id";
            $params = [':n'=>$name, ':id'=>$accId];
            if ($accHasUser) { $sql .= " AND user_id = :u"; $params[':u'] = $userId; }
            $pdo->prepare($sql)->execute($params);

            Util::addFlash('success', "Compte modifié.");
            Util::redirect('accounts.php');
        }

        // Supprimer un compte (avec suppression de ses transactions)
        if ($action === 'delete_account') {
            $accId = (int)($_POST['account_id'] ?? 0);
            if ($accId <= 0) throw new RuntimeException("Compte invalide.");

            ensureAccountOwned($pdo, $accId, $userId, $accHasUser);
            $n = countAccountTransactions($pdo, $accId, $userId, $trxHasUser);

            $pdo->beginTransaction();
            try {
                if ($n > 0) {
                    $sqlDelTrx = "DELETE FROM transactions WHERE account_id = :id";
                    $params = [':id'=>$accId];
                    if ($trxHasUser) { $sqlDelTrx .= " AND user_id = :u"; $params[':u'] = $userId; }
                    $pdo->prepare($sqlDelTrx)->execute($params);
                }
                $sqlDelAcc = "DELETE FROM accounts WHERE id = :id";
                $params2 = [':id'=>$accId];
                if ($accHasUser) { $sqlDelAcc .= " AND user_id = :u"; $params2[':u'] = $userId; }
                $pdo->prepare($sqlDelAcc)->execute($params2);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            Util::addFlash('success', "Compte supprimé.".($n>0 ? " ($n transaction(s) supprimée(s))" : ""));
            Util::redirect('accounts.php');
        }

        throw new RuntimeException("Action non reconnue.");

    } catch (Throwable $e) {
        Util::addFlash('danger', $e->getMessage());
        Util::redirect('accounts.php');
    }
    exit;
}

// Liste des comptes
$sqlList = "SELECT id, name".($accHasMicro ? ", micro_enterprise_id" : "").($accHasUser ? ", user_id" : "")." FROM accounts";
$paramsList = [];
if ($accHasUser) { $sqlList .= " WHERE user_id = :u"; $paramsList[':u'] = $userId; }
$sqlList .= " ORDER BY id ASC";
$st = $pdo->prepare($sqlList);
$st->execute($paramsList);
$accounts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Nombre de transactions par compte
$counts = [];
if ($accounts) {
    $ids = array_map(fn($r)=>(int)$r['id'], $accounts);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $sqlCnt = "SELECT account_id, COUNT(*) AS n FROM transactions WHERE account_id IN ($in)";
    $bind = $ids;
    if ($trxHasUser) { $sqlCnt .= " AND user_id = ?"; $bind[] = $userId; }
    $sqlCnt .= " GROUP BY account_id";
    $stc = $pdo->prepare($sqlCnt);
    $stc->execute($bind);
    foreach ($stc->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[(int)$row['account_id']] = (int)$row['n'];
    }
}

// Soldes par compte + total
$balances = [];
$totalAll = 0.0;
if ($accounts) {
    $ids = array_map(fn($r)=>(int)$r['id'], $accounts);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $sqlBal = "SELECT account_id, ROUND(SUM(amount), 2) AS bal FROM transactions WHERE account_id IN ($in)";
    $bindB = $ids;
    if ($trxHasUser) { $sqlBal .= " AND user_id = ?"; $bindB[] = $userId; }
    $sqlBal .= " GROUP BY account_id";
    $stb = $pdo->prepare($sqlBal);
    $stb->execute($bindB);
    foreach ($stb->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $balances[(int)$row['account_id']] = (float)($row['bal'] ?? 0);
    }
    foreach ($accounts as $a) {
        $totalAll += (float)($balances[(int)$a['id']] ?? 0.0);
    }
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Comptes</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f5f6f8; }
.badge-micro { background:#0d6efd; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Courier New", monospace; }
</style>
</head>
<body>
<?php include __DIR__.'/_nav.php'; ?>

<div class="container py-3">

  <?php foreach (Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Nouveau compte</strong></div>
        <div class="card-body">
          <form method="post" id="newAccountForm">
            <?= Util::csrfInput() ?>
            <input type="hidden" name="action" value="create_account">
            <div class="mb-2">
              <label class="form-label">Nom du compte</label>
              <input type="text" name="name" class="form-control form-control-sm" required placeholder="Ex: Compte courant">
            </div>
            <div class="mb-2">
              <label class="form-label">Type</label>
              <select name="kind" id="kind" class="form-select form-select-sm">
                <option value="personal" selected>Personnel</option>
                <option value="micro" <?= ($microRow && $accHasMicro) ? '' : 'disabled' ?>>Micro</option>
              </select>
            </div>
            <div class="mb-2" id="activityBlock" style="display:none;">
              <label class="form-label">Activité (micro)</label>
              <select name="activity_code" class="form-select form-select-sm" <?= ($microRow) ? '' : 'disabled' ?>>
                <?php
                  $pref = $existingCode ?: '';
                  foreach ($activityOptions as $code => $label):
                    $sel = (strtolower((string)$code) === strtolower((string)$pref)) ? 'selected' : '';
                ?>
                  <option value="<?= h((string)$code) ?>" <?= $sel ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Les plafonds et taux seront appliqués automatiquement.</div>
            </div>
            <button class="btn btn-primary btn-sm">Créer</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <strong>Mes comptes</strong>
          <small class="text-muted">
            <?= count($accounts) ?> compte(s)
            <?php if ($accounts): ?>
              • Total: <span class="mono"><?= fmt($totalAll) ?> €</span>
            <?php endif; ?>
          </small>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Nom</th>
                  <th>Type</th>
                  <th class="text-end">Solde</th>
                  <th class="text-end">Transactions</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$accounts): ?>
                <tr><td colspan="6" class="text-muted">Aucun compte.</td></tr>
              <?php else: foreach ($accounts as $a): ?>
                <?php
                  $isMicro = $accHasMicro && !empty($a['micro_enterprise_id']);
                  $nTrx = $counts[(int)$a['id']] ?? 0;
                  $bal  = $balances[(int)$a['id']] ?? 0.0;
                  $isEditing = $editId === (int)$a['id'];
                ?>
                <tr>
                  <td><?= (int)$a['id'] ?></td>
                  <td>
                    <?php if ($isEditing): ?>
                      <form method="post" class="d-flex gap-2 align-items-center">
                        <?= Util::csrfInput() ?>
                        <input type="hidden" name="action" value="update_account">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <input type="text" class="form-control form-control-sm" name="name" value="<?= h($a['name']) ?>" required>
                        <button class="btn btn-sm btn-primary">Enregistrer</button>
                        <a class="btn btn-sm btn-outline-secondary" href="accounts.php">Annuler</a>
                      </form>
                    <?php else: ?>
                      <?= h($a['name']) ?><?= $isMicro ? ' <span class="badge badge-micro">Micro</span>' : '' ?>
                    <?php endif; ?>
                  </td>
                  <td><?= $isMicro ? 'Micro' : 'Personnel' ?></td>
                  <td class="text-end mono"><?= fmt($bal) ?> €</td>
                  <td class="text-end"><?= $nTrx ?></td>
                  <td class="text-end">
                    <?php if (!$isEditing): ?>
                      <a class="btn btn-sm btn-outline-secondary" href="accounts.php?edit=<?= (int)$a['id'] ?>">Modifier</a>
                    <?php endif; ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ce compte et toutes ses transactions ?');">
                      <?= Util::csrfInput() ?>
                      <input type="hidden" name="action" value="delete_account">
                      <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">Supprimer</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
              <?php if ($accounts): ?>
              <tfoot>
                <tr>
                  <th colspan="3" class="text-end">Total</th>
                  <th class="text-end mono"><?= fmt($totalAll) ?> €</th>
                  <th colspan="2"></th>
                </tr>
              </tfoot>
              <?php endif; ?>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Affiche/masque le bloc "Activité" selon le type choisi
(function(){
  var kind = document.getElementById('kind');
  var block = document.getElementById('activityBlock');
  function toggle() { if (kind && block) block.style.display = (kind.value === 'micro') ? '' : 'none'; }
  if (kind) { kind.addEventListener('change', toggle); toggle(); }
})();
</script>
</body>
</html>