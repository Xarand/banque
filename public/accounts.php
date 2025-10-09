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
function hasCol(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("PRAGMA table_info($table)");
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (strcasecmp((string)$c['name'], $col) === 0) return true;
    }
    return false;
}
$accHasUser  = hasCol($pdo, 'accounts', 'user_id');
$accHasMicro = hasCol($pdo, 'accounts', 'micro_enterprise_id');
$accHasCAts  = hasCol($pdo, 'accounts', 'created_at');
$trxHasUser  = hasCol($pdo, 'transactions', 'user_id');

// Micro de l'utilisateur (pour rattacher un compte micro)
$microRow = null;
try {
    $microRepo = new MicroEnterpriseRepository($pdo);
    $list = $microRepo->listMicro($userId);
    $microRow = $list ? $list[0] : null;
} catch (Throwable $e) {
    $microRow = null;
}

// Fonctions utilitaires pour contrôles
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

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();
        $action = (string)($_POST['action'] ?? '');

        // Création compte
        if ($action === 'create_account') {
            $name = trim((string)($_POST['name'] ?? ''));
            $kind = (string)($_POST['kind'] ?? 'personal'); // personal|micro
            if ($name === '') throw new RuntimeException("Le nom du compte est requis.");

            $microId = null;
            if ($kind === 'micro') {
                if (!$accHasMicro) throw new RuntimeException("Le schéma ne supporte pas les comptes micro (colonne micro_enterprise_id absente).");
                if ($microRow === null) throw new RuntimeException("Aucune micro disponible pour rattacher ce compte.");
                $microId = (int)$microRow['id'];

                // 1 seul compte micro par utilisateur
                $sqlChk = "SELECT COUNT(*) FROM accounts WHERE micro_enterprise_id = :mid";
                $paramsChk = [':mid'=>$microId];
                if ($accHasUser) { $sqlChk .= " AND user_id = :u"; $paramsChk[':u'] = $userId; }
                $stChk = $pdo->prepare($sqlChk);
                $stChk->execute($paramsChk);
                if ((int)$stChk->fetchColumn() > 0) {
                    throw new RuntimeException("Un compte micro existe déjà. Convertis-le en personnel ou supprime-le avant d'en créer un autre.");
                }
            }

            $cols = ['name']; $vals = [':name']; $bind = [':name'=>$name];
            if ($accHasUser)  { $cols[]='user_id';               $vals[]=':uid'; $bind[':uid']=$userId; }
            if ($accHasMicro) { $cols[]='micro_enterprise_id';   $vals[]=':mid'; $bind[':mid']=$microId; }
            if ($accHasCAts)  { $cols[]='created_at';            $vals[]="datetime('now')"; }

            $sql = "INSERT INTO accounts(".implode(',', $cols).") VALUES (".implode(',', $vals).")";
            $pdo->prepare($sql)->execute($bind);

            Util::addFlash('success', "Compte créé.");
            Util::redirect('accounts.php');
        }

        // Suppression compte
        if ($action === 'delete_account') {
            $accId   = (int)($_POST['account_id'] ?? 0);
            $cascade = !empty($_POST['cascade']);
            $acc = ensureAccountOwned($pdo, $accId, $userId, $accHasUser);
            $n   = countAccountTransactions($pdo, $accId, $userId, $trxHasUser);

            if ($n > 0 && !$cascade) {
                throw new RuntimeException("Ce compte contient $n transaction(s). Coche “Supprimer aussi les transactions”.");
            }

            $pdo->beginTransaction();
            try {
                if ($n > 0 && $cascade) {
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

            Util::addFlash('success', "Compte supprimé.".($n>0 && $cascade ? " ($n transactions supprimées)" : ""));
            Util::redirect('accounts.php');
        }

        // Convertir en personnel (détacher de la micro)
        if ($action === 'make_personal') {
            if (!$accHasMicro) throw new RuntimeException("Le schéma ne gère pas micro_enterprise_id.");
            $accId = (int)($_POST['account_id'] ?? 0);
            ensureAccountOwned($pdo, $accId, $userId, $accHasUser);

            $sql = "UPDATE accounts SET micro_enterprise_id = NULL WHERE id = :id";
            $params = [':id'=>$accId];
            if ($accHasUser) { $sql .= " AND user_id = :u"; $params[':u'] = $userId; }
            $pdo->prepare($sql)->execute($params);

            Util::addFlash('success', "Compte converti en personnel (détaché de la micro).");
            Util::redirect('accounts.php');
        }

        // Convertir en micro (si aucun compte micro n’existe encore)
        if ($action === 'make_micro') {
            if (!$accHasMicro) throw new RuntimeException("Le schéma ne gère pas micro_enterprise_id.");
            if ($microRow === null) throw new RuntimeException("Aucune micro disponible.");
            $accId = (int)($_POST['account_id'] ?? 0);
            ensureAccountOwned($pdo, $accId, $userId, $accHasUser);

            // Vérifier unicité
            $sqlChk = "SELECT COUNT(*) FROM accounts WHERE micro_enterprise_id = :mid";
            $paramsChk = [':mid'=>(int)$microRow['id']];
            if ($accHasUser) { $sqlChk .= " AND user_id = :u"; $paramsChk[':u'] = $userId; }
            $st = $pdo->prepare($sqlChk);
            $st->execute($paramsChk);
            if ((int)$st->fetchColumn() > 0) {
                throw new RuntimeException("Un compte micro existe déjà. Détache-le avant de convertir ce compte.");
            }

            $sql = "UPDATE accounts SET micro_enterprise_id = :mid WHERE id = :id";
            $params = [':mid'=>(int)$microRow['id'], ':id'=>$accId];
            if ($accHasUser) { $sql .= " AND user_id = :u"; $params[':u'] = $userId; }
            $pdo->prepare($sql)->execute($params);

            Util::addFlash('success', "Compte converti en micro (rattaché à ".h($microRow['name']).").");
            Util::redirect('accounts.php');
        }

        // Action inconnue
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
          <form method="post">
            <?= Util::csrfInput() ?>
            <input type="hidden" name="action" value="create_account">
            <div class="mb-2">
              <label class="form-label">Nom du compte</label>
              <input type="text" name="name" class="form-control form-control-sm" required placeholder="Ex: Compte courant">
            </div>
            <div class="mb-2">
              <label class="form-label">Type</label>
              <select name="kind" class="form-select form-select-sm">
                <option value="personal" selected>Personnel</option>
                <option value="micro" <?= ($microRow && $accHasMicro) ? '' : 'disabled' ?>>
                  Micro (rattaché à <?= $microRow ? h($microRow['name']) : '—' ?>)
                </option>
              </select>
              <div class="form-text">
                Personnel = non rattaché. Micro = rattaché à ta micro pour le calcul du CA.
              </div>
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
          <small class="text-muted"><?= count($accounts) ?> compte(s)</small>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Nom</th>
                  <th>Type</th>
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
                ?>
                <tr>
                  <td><?= (int)$a['id'] ?></td>
                  <td><?= h($a['name']) ?><?= $isMicro ? ' <span class="badge badge-micro">Micro</span>' : '' ?></td>
                  <td><?= $isMicro ? 'Micro' : 'Personnel' ?></td>
                  <td class="text-end"><?= $nTrx ?></td>
                  <td class="text-end">
                    <?php if ($isMicro): ?>
                      <!-- Convertir en personnel -->
                      <form method="post" class="d-inline" onsubmit="return confirm('Convertir ce compte en personnel (le détacher de la micro) ?');">
                        <?= Util::csrfInput() ?>
                        <input type="hidden" name="action" value="make_personal">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-sm btn-outline-secondary">Convertir en personnel</button>
                      </form>
                    <?php elseif ($microRow): ?>
                      <!-- Convertir en micro (si aucun autre compte micro n’existe) -->
                      <form method="post" class="d-inline" onsubmit="return confirm('Convertir ce compte en micro (rattacher à <?= h($microRow['name']) ?>) ?');">
                        <?= Util::csrfInput() ?>
                        <input type="hidden" name="action" value="make_micro">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-sm btn-outline-primary">Convertir en micro</button>
                      </form>
                    <?php endif; ?>

                    <!-- Supprimer -->
                    <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ce compte<?= $nTrx>0 ? ' et ses transactions' : '' ?> ?');">
                      <?= Util::csrfInput() ?>
                      <input type="hidden" name="action" value="delete_account">
                      <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                      <?php if ($nTrx>0): ?>
                        <label class="me-2 small"><input type="checkbox" name="cascade" value="1"> Supprimer aussi les transactions</label>
                      <?php endif; ?>
                      <button class="btn btn-sm btn-outline-danger">Supprimer</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="mt-2">
        <a class="btn btn-sm btn-secondary" href="index.php">Retour au tableau</a>
        <?php if ($microRow): ?>
          <a class="btn btn-sm btn-outline-primary" href="micro_view.php?id=<?= (int)$microRow['id'] ?>">Aller à ma micro</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>