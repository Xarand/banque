<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database};

ini_set('display_errors','1');
error_reporting(E_ALL);

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

function h(string $s): string { return App\Util::h($s); }
function fmt(float $n): string { return number_format($n, 2, ',', ' '); }

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
function ensureColumn(PDO $pdo, string $table, string $col, string $typeSql): void {
    if (!hasCol($pdo, $table, $col)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $col $typeSql");
    }
}
function ensureMicroTable(PDO $pdo): void {
    if (!hasTable($pdo, 'micro_enterprises')) {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS micro_enterprises (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            activity_code TEXT,
            created_at TEXT,
            declaration_period TEXT,
            versement_liberatoire INTEGER DEFAULT 0,
            ca_ceiling REAL,
            vat_ceiling REAL,
            vat_ceiling_major REAL,
            social_contrib_rate REAL,
            income_tax_rate REAL,
            cfp_rate REAL,
            cma_rate REAL
          );
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS ix_micro_user ON micro_enterprises(user_id)");
    } else {
        foreach ([
            ['user_id','INTEGER'],
            ['activity_code','TEXT'],
            ['created_at','TEXT'],
            ['declaration_period','TEXT'],
            ['versement_liberatoire','INTEGER DEFAULT 0'],
            ['ca_ceiling','REAL'],
            ['vat_ceiling','REAL'],
            ['vat_ceiling_major','REAL'],
            ['social_contrib_rate','REAL'],
            ['income_tax_rate','REAL'],
            ['cfp_rate','REAL'],
            ['cma_rate','REAL'],
        ] as [$c,$t]) ensureColumn($pdo, 'micro_enterprises', $c, $t);
    }
}

ensureMicroTable($pdo);

// Schéma comptes/transactions
$accHasUser  = hasCol($pdo, 'accounts', 'user_id');
$accHasMicro = hasCol($pdo, 'accounts', 'micro_enterprise_id');
$accHasCAts  = hasCol($pdo, 'accounts', 'created_at');
$trxHasUser  = hasCol($pdo, 'transactions', 'user_id');

// Micro existante
$st = $pdo->prepare("SELECT * FROM micro_enterprises WHERE user_id = :u LIMIT 1");
$st->execute([':u'=>$userId]);
$microRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;

// Un seul compte Micro autorisé
$hasMicroAccount = false;
try {
    if ($accHasMicro) {
        $sql = "SELECT COUNT(*) FROM accounts WHERE 1=1";
        $bind = [];
        if ($accHasUser) { $sql .= " AND user_id = :u"; $bind[':u'] = $userId; }
        if ($microRow)   { $sql .= " AND micro_enterprise_id = :mid"; $bind[':mid'] = (int)$microRow['id']; }
        else             { $sql .= " AND micro_enterprise_id IS NOT NULL"; }
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        $hasMicroAccount = ((int)$st->fetchColumn() > 0);
    }
} catch (Throwable $e) {
    $hasMicroAccount = false;
}

// Référentiel activités
$activities = [];
$cfgPath = __DIR__.'/../config/micro_activities.php';
if (is_file($cfgPath)) {
    $activities = require $cfgPath;
} else {
    $activities = [
        'vente'          => ['label'=>'Vente de marchandises','ceilings'=>['ca'=>188700,'vat'=>91900,'vat_major'=>101000],'rates'=>['social'=>0.123,'income_tax'=>0.01,'cfp'=>0.001,'cma'=>0.00015]],
        'service'        => ['label'=>'Prestations de services','ceilings'=>['ca'=>77700,'vat'=>36800,'vat_major'=>39100],'rates'=>['social'=>0.212,'income_tax'=>0.017,'cfp'=>0.003,'cma'=>0.00015]],
        'liberal_cipav'  => ['label'=>'Professions libérales CIPAV','ceilings'=>['ca'=>77700,'vat'=>36800,'vat_major'=>39100],'rates'=>['social'=>0.232,'income_tax'=>0.022,'cfp'=>0.002,'cma'=>0.0]],
        'liberal_ssi'    => ['label'=>'Professions libérales SSI','ceilings'=>['ca'=>77700,'vat'=>36800,'vat_major'=>39100],'rates'=>['social'=>0.246,'income_tax'=>0.022,'cfp'=>0.002,'cma'=>0.0]],
        'meuble_classe'  => ['label'=>'Meublé tourisme classé','ceilings'=>['ca'=>77700,'vat'=>36800,'vat_major'=>39100],'rates'=>['social'=>0.06,'income_tax'=>0.01,'cfp'=>0.01,'cma'=>0.00015]],
    ];
}

// Helpers
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

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_account') {
            $name = trim((string)($_POST['name'] ?? ''));
            $kind = (string)($_POST['kind'] ?? 'personal');
            if ($name === '') throw new RuntimeException("Le nom du compte est requis.");

            $microId = null;
            if ($kind === 'micro') {
                if (!$accHasMicro) throw new RuntimeException("Le schéma ne supporte pas les comptes micro.");
                $creationDate = trim((string)($_POST['micro_created_at'] ?? ''));
                $activityCode = trim((string)($_POST['activity_code'] ?? ''));
                $liberatoire  = isset($_POST['versement_liberatoire']) ? 1 : 0;
                $declPeriod   = (string)($_POST['declaration_period'] ?? 'quarterly');

                if ($activityCode === '' || !isset($activities[$activityCode])) {
                    throw new RuntimeException("Activité invalide.");
                }
                if ($hasMicroAccount) {
                    throw new RuntimeException("Un seul compte Micro est autorisé.");
                }

                if (!$microRow) {
                    $pdo->prepare("
                      INSERT INTO micro_enterprises (user_id, activity_code, created_at, declaration_period, versement_liberatoire)
                      VALUES (:u, :ac, :dt, :dp, :vl)
                    ")->execute([
                        ':u'=>$userId, ':ac'=>$activityCode, ':dt'=>$creationDate !== '' ? $creationDate : date('Y-m-d'), ':dp'=>$declPeriod, ':vl'=>$liberatoire
                    ]);
                    $mid = (int)$pdo->lastInsertId();
                    $st = $pdo->prepare("SELECT * FROM micro_enterprises WHERE id = :id");
                    $st->execute([':id'=>$mid]);
                    $microRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                } else {
                    $pdo->prepare("
                      UPDATE micro_enterprises
                      SET activity_code=:ac, created_at=COALESCE(NULLIF(:dt,''), created_at), declaration_period=:dp, versement_liberatoire=:vl
                      WHERE id = :id AND user_id = :u
                    ")->execute([
                        ':ac'=>$activityCode, ':dt'=>$creationDate, ':dp'=>$declPeriod, ':vl'=>$liberatoire, ':id'=>(int)$microRow['id'], ':u'=>$userId
                    ]);
                }

                $def = $activities[$activityCode];
                $incomeTaxRate = $liberatoire ? (float)$def['rates']['income_tax'] : 0.0;

                $pdo->prepare("
                  UPDATE micro_enterprises SET
                    ca_ceiling            = :ca,
                    vat_ceiling           = :vat,
                    vat_ceiling_major     = :vatm,
                    social_contrib_rate   = :rs,
                    income_tax_rate       = :ri,
                    cfp_rate              = :rcfp,
                    cma_rate              = :rcma
                  WHERE user_id = :u
                ")->execute([
                    ':ca'=>(float)$def['ceilings']['ca'],
                    ':vat'=>(float)$def['ceilings']['vat'],
                    ':vatm'=>(float)$def['ceilings']['vat_major'],
                    ':rs'=>(float)$def['rates']['social'],
                    ':ri'=>$incomeTaxRate,
                    ':rcfp'=>(float)$def['rates']['cfp'],
                    ':rcma'=>(float)$def['rates']['cma'],
                    ':u'=>$userId,
                ]);

                $microId = $microRow ? (int)$microRow['id'] : (int)$pdo->lastInsertId();
            }

            $cols = ['name']; $vals = [':name']; $bind = [':name'=>$name];
            if ($accHasUser)  { $cols[]='user_id';             $vals[]=':uid'; $bind[':uid']=$userId; }
            if ($accHasMicro) { $cols[]='micro_enterprise_id'; $vals[]=':mid'; $bind[':mid'] = $microId; }
            if ($accHasCAts)  { $cols[]='created_at';          $vals[]="datetime('now')"; }

            $sql = "INSERT INTO accounts(".implode(',', $cols).") VALUES (".implode(',', $vals).")";
            $pdo->prepare($sql)->execute($bind);

            Util::addFlash('success', "Compte créé.".($kind==='micro' ? " Micro configuré." : ""));
            Util::redirect('accounts.php');
        }

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

// Nombre de transactions
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

// Soldes + total
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
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
/* Checkboxes plus visibles */
.form-check-input.checkbox-strong {
  transform: scale(1.25);
  border-width: 2px;
  accent-color: #0d6efd; /* pleine couleur quand cochée (navigateurs récents) */
}
.form-check-input.checkbox-strong:checked {
  background-color: #0d6efd;
  border-color: #0d6efd;
  box-shadow: 0 0 0 .2rem rgba(13,110,253,.25);
}
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
                <option value="micro" <?= ($accHasMicro && !$hasMicroAccount) ? '' : 'disabled' ?>>Micro</option>
              </select>
              <?php if ($hasMicroAccount): ?>
                <div class="form-text text-warning">Un seul compte Micro est autorisé.</div>
              <?php endif; ?>
            </div>

            <!-- Options Micro -->
            <div id="microOptions" style="display:none;">
              <hr class="my-2">
              <div class="mb-2">
                <label class="form-label">Date de création</label>
                <input type="date" name="micro_created_at" class="form-control form-control-sm" value="<?= h(date('Y-m-d')) ?>">
              </div>

              <div class="mb-2">
                <label class="form-label">Type d'activité</label>
                <select name="activity_code" class="form-select form-select-sm" <?= $hasMicroAccount ? 'disabled' : '' ?> required>
                  <?php foreach ($activities as $code=>$def): ?>
                    <option value="<?= h((string)$code) ?>"><?= h((string)$def['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-check mb-2">
                <input class="form-check-input checkbox-strong" type="checkbox" name="versement_liberatoire" id="vl">
                <label class="form-check-label" for="vl">Impôt libératoire</label>
              </div>

              <div class="mb-2">
                <label class="form-label">Déclaration de CA</label>
                <select name="declaration_period" class="form-select form-select-sm">
                  <option value="monthly">Mensuelle</option>
                  <option value="quarterly" selected>Trimestrielle</option>
                </select>
              </div>
            </div>

            <button class="btn btn-primary btn-sm mt-2">Créer</button>
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
                  <th>Nom</th>
                  <th>Type</th>
                  <th class="text-end">Solde</th>
                  <th class="text-end">Transactions</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$accounts): ?>
                <tr><td colspan="5" class="text-muted">Aucun compte.</td></tr>
              <?php else: foreach ($accounts as $a): ?>
                <?php
                  $isMicro = $accHasMicro && !empty($a['micro_enterprise_id']);
                  $nTrx = $counts[(int)$a['id']] ?? 0;
                  $bal  = $balances[(int)$a['id']] ?? 0.0;
                  $isEditing = $editId === (int)$a['id'];
                ?>
                <tr>
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
                  <th class="text-end" colspan="2">Total</th>
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
// Affiche/masque les options Micro selon le type choisi
(function(){
  var kind = document.getElementById('kind');
  var block = document.getElementById('microOptions');
  function toggle() { if (kind && block) block.style.display = (kind.value === 'micro') ? '' : 'none'; }
  if (kind) { kind.addEventListener('change', toggle); toggle(); }
})();
</script>
</body>
</html>