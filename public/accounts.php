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

// Micro de l'utilisateur (pour rattacher le compte micro)
$microRow = null;
try {
    $microRepo = new MicroEnterpriseRepository($pdo);
    $list = $microRepo->listMicro($userId);
    // On ne crée rien automatiquement ici: si pas de micro, le type "Micro" sera désactivé
    $microRow = $list ? $list[0] : null;
} catch (Throwable $e) {
    $microRow = null; // si pas de tables micro, on continue (comptes perso OK)
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();

        // Création compte
        if (($_POST['action'] ?? '') === 'create_account') {
            $name = trim((string)($_POST['name'] ?? ''));
            $kind = (string)($_POST['kind'] ?? 'personal'); // personal|micro

            if ($name === '') {
                throw new RuntimeException("Le nom du compte est requis.");
            }

            $microId = null;
            if ($kind === 'micro') {
                if (!$accHasMicro) {
                    throw new RuntimeException("Le schéma ne supporte pas les comptes micro (colonne micro_enterprise_id absente).");
                }
                if ($microRow === null) {
                    throw new RuntimeException("Aucune micro n'est disponible pour y rattacher un compte.");
                }
                $microId = (int)$microRow['id'];

                // CONTRAINTE: 1 seul compte micro par utilisateur
                // On vérifie s'il existe déjà un compte rattaché à la micro de cet utilisateur
                $sqlChk = "SELECT COUNT(*) FROM accounts WHERE micro_enterprise_id = :mid";
                $paramsChk = [':mid' => $microId];
                if ($accHasUser) {
                    $sqlChk .= " AND user_id = :u";
                    $paramsChk[':u'] = $userId;
                }
                $stChk = $pdo->prepare($sqlChk);
                $stChk->execute($paramsChk);
                $already = (int)$stChk->fetchColumn();
                if ($already > 0) {
                    throw new RuntimeException("Un compte micro existe déjà pour cet utilisateur. Supprime-le avant d'en créer un autre.");
                }
            }

            // Construction INSERT dynamique
            $cols   = ['name'];
            $values = [':name'];
            $bind   = [':name' => $name];

            if ($accHasUser) {
                $cols[] = 'user_id'; $values[] = ':uid'; $bind[':uid'] = $userId;
            }
            if ($accHasMicro) {
                $cols[] = 'micro_enterprise_id'; $values[] = ':mid'; $bind[':mid'] = $microId;
            }
            if ($accHasCAts) {
                $cols[] = 'created_at'; $values[] = "datetime('now')";
            }

            $sql = "INSERT INTO accounts(".implode(',', $cols).") VALUES (".implode(',', $values).")";
            $st  = $pdo->prepare($sql);
            $st->execute($bind);

            Util::addFlash('success', "Compte créé.");
            Util::redirect('accounts.php');
        }

        // Suppression compte
        if (($_POST['action'] ?? '') === 'delete_account') {
            $accId   = (int)($_POST['account_id'] ?? 0);
            $cascade = !empty($_POST['cascade']);

            if ($accId <= 0) throw new RuntimeException("Compte invalide.");

            // Vérifier que le compte existe (et appartient à l'utilisateur si colonne user_id)
            $sqlAcc = "SELECT * FROM accounts WHERE id = :id";
            $bindAcc = [':id' => $accId];
            if ($accHasUser) { $sqlAcc .= " AND user_id = :u"; $bindAcc[':u'] = $userId; }
            $stAcc = $pdo->prepare($sqlAcc);
            $stAcc->execute($bindAcc);
            $acc = $stAcc->fetch(PDO::FETCH_ASSOC);
            if (!$acc) throw new RuntimeException("Compte introuvable.");

            // Compter les transactions de ce compte
            $sqlCnt = "SELECT COUNT(*) FROM transactions WHERE account_id = :id";
            $bindCnt = [':id' => $accId];
            if ($trxHasUser) { $sqlCnt .= " AND user_id = :u"; $bindCnt[':u'] = $userId; }
            $stc = $pdo->prepare($sqlCnt);
            $stc->execute($bindCnt);
            $n = (int)$stc->fetchColumn();

            if ($n > 0 && !$cascade) {
                throw new RuntimeException("Ce compte contient $n transaction(s). Coche “Supprimer aussi les transactions” pour une suppression en cascade.");
            }

            $pdo->beginTransaction();
            try {
                if ($n > 0 && $cascade) {
                    $sqlDelTrx = "DELETE FROM transactions WHERE account_id = :id";
                    if ($trxHasUser) $sqlDelTrx .= " AND user_id = :u";
                    $std = $pdo->prepare($sqlDelTrx);
                    $std->execute($bindCnt);
                }
                $sqlDelAcc = "DELETE FROM accounts WHERE id = :id";
                if ($accHasUser) $sqlDelAcc .= " AND user_id = :u";
                $std2 = $pdo->prepare($sqlDelAcc);
                $std2->execute($bindAcc);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            Util::addFlash('success', "Compte supprimé.".($n>0 && $cascade ? " ($n transactions supprimées)" : ""));
            Util::redirect('accounts.php');
        }

    } catch (Throwable $e) {
        Util::addFlash('danger', $e->getMessage());
        Util::redirect('accounts.php');
    }
    exit;
}

// Récupération des comptes pour affichage
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

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3">
  <div class="container">
    <a class="navbar-brand" href="index.php">Banque</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topnav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="topnav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="index.php">Tableau</a></li>
        <li class="nav-item"><a class="nav-link" href="reports.php">Rapports</a></li>
        <li class="nav-item"><a class="nav-link" href="micro_index.php">Micro</a></li>
        <li class="nav-item"><a class="nav-link active" href="accounts.php">Comptes</a></li>
      </ul>
      <div class="d-flex">
        <a class="btn btn-outline-light btn-sm" href="logout.php">Déconnexion</a>
      </div>
    </div>
  </div>
</nav>

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
              <input type="text" name="name" class="form-control form-control-sm" required placeholder="Ex: Compte pro">
            </div>
            <div class="mb-2">
              <label class="form-label">Type</label>
              <select name="kind" id="kind" class="form-select form-select-sm">
                <option value="personal">Personnel</option>
                <option value="micro" <?= ($microRow && $accHasMicro) ? '' : 'disabled' ?>>
                  Micro (rattaché à <?= $microRow ? h($microRow['name']) : '—' ?>)
                </option>
              </select>
              <?php if (!$accHasMicro): ?>
                <div class="form-text text-danger">Le schéma de la table accounts ne possède pas micro_enterprise_id.</div>
              <?php elseif (!$microRow): ?>
                <div class="form-text">Crée d'abord ta micro pour activer ce type.</div>
              <?php else: ?>
                <div class="form-text">Règle: 1 seul compte micro par utilisateur.</div>
              <?php endif; ?>
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
                    <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ce compte<?= $nTrx>0 ? ' et ses transactions' : '' ?> ?');">
                      <?= Util::csrfInput() ?>
                      <input type="hidden" name="action" value="delete_account">
                      <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                      <?php if ($nTrx>0): ?>
                        <label class="me-2 small">
                          <input type="checkbox" name="cascade" value="1"> Supprimer aussi les transactions
                        </label>
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