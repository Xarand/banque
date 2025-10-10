<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database};

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

$trxHasUser   = hasCol($pdo, 'transactions', 'user_id');
$trxHasCat    = hasCol($pdo, 'transactions', 'category_id');
$trxHasExCa   = hasCol($pdo, 'transactions', 'exclude_from_ca');
$accHasUser   = hasCol($pdo, 'accounts', 'user_id');

// Comptes pour sélecteur
$sqlAcc = "SELECT id, name".($accHasUser ? ", user_id" : "")." FROM accounts";
$paramsAcc = [];
if ($accHasUser) { $sqlAcc .= " WHERE user_id = :u"; $paramsAcc[':u'] = $userId; }
$sqlAcc .= " ORDER BY name ASC";
$stA = $pdo->prepare($sqlAcc);
$stA->execute($paramsAcc);
$accounts = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Catégories (si table présente) + type (credit|debit)
$categories = [];
$catTypeMap = []; // [id => 'credit'|'debit']
$catHasUser = false;
if ($trxHasCat) {
    $catHasUser = hasCol($pdo, 'categories', 'user_id');
    try {
        $sqlC = "SELECT id, name, COALESCE(NULLIF(type,''),'debit') AS type FROM categories";
        $paramsC = [];
        if ($catHasUser) { $sqlC .= " WHERE user_id = :u"; $paramsC[':u'] = $userId; }
        $sqlC .= " ORDER BY name ASC";
        $stC = $pdo->prepare($sqlC);
        $stC->execute($paramsC);
        $categories = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($categories as $c) {
            $cid = (int)$c['id'];
            $ctype = strtolower((string)$c['type']);
            if ($ctype !== 'credit' && $ctype !== 'debit') $ctype = 'debit';
            $catTypeMap[$cid] = $ctype;
        }
    } catch (Throwable $e) {
        $categories = [];
        $catTypeMap = [];
    }
}

// Mode édition ou création
$isNew = isset($_GET['new']) && (string)$_GET['new'] === '1';
$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) $isNew = false;

// Valeurs par défaut
$data = [
    'date' => date('Y-m-d'),
    'account_id' => isset($_GET['account_id']) ? (int)$_GET['account_id'] : ( ($accounts[0]['id'] ?? 0) ),
    'amount' => 0.00,
    'description' => '',
    'notes' => '',
    'category_id' => null,
    'exclude_from_ca' => 0
];

// Charger pour édition
if (!$isNew && $id > 0) {
    $sql = "SELECT * FROM transactions WHERE id = :id";
    $params = [':id' => $id];
    if ($trxHasUser) { $sql .= " AND user_id = :u"; $params[':u'] = $userId; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        Util::addFlash('danger', 'ID invalide.');
        Util::redirect('index.php');
        exit;
    }
    $data = array_merge($data, $row);
} elseif (!$isNew && $id <= 0) {
    // Quand ni id valide ni new=1 => protège
    Util::addFlash('danger', 'ID invalide.');
    Util::redirect('index.php');
    exit;
}

// POST: enregistrer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();

        $pid   = (int)($_POST['id'] ?? 0);
        $isNew = ($pid === 0);

        $date   = trim((string)($_POST['date'] ?? date('Y-m-d')));
        $accId  = (int)($_POST['account_id'] ?? 0);
        $amount = (float)str_replace(',', '.', (string)($_POST['amount'] ?? '0'));
        $desc   = trim((string)($_POST['description'] ?? ''));
        $notes  = trim((string)($_POST['notes'] ?? ''));
        $catId  = $trxHasCat ? (int)($_POST['category_id'] ?? 0) : 0;
        $exCa   = $trxHasExCa ? (int)!empty($_POST['exclude_from_ca']) : 0;

        if ($accId <= 0) throw new RuntimeException("Compte requis.");
        if ($date === '') throw new RuntimeException("Date requise.");

        // Sécurité: vérifier que le compte appartient à l'utilisateur si accounts.user_id existe
        if ($accHasUser) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE id=:a AND user_id=:u");
            $chk->execute([':a'=>$accId, ':u'=>$userId]);
            if ((int)$chk->fetchColumn() === 0) throw new RuntimeException("Compte non autorisé.");
        }

        // Appliquer le signe selon la catégorie (si présente et type connu)
        if ($trxHasCat && $catId > 0) {
            // Récupère le type depuis la map; si absent, essaie en DB
            $ctype = $catTypeMap[$catId] ?? null;
            if ($ctype === null) {
                $sql = "SELECT COALESCE(NULLIF(type,''),'debit') FROM categories WHERE id=:id";
                $p = [':id'=>$catId];
                if ($catHasUser) { $sql .= " AND user_id=:u"; $p[':u'] = $userId; }
                $st = $pdo->prepare($sql); $st->execute($p);
                $ctype = strtolower((string)$st->fetchColumn());
                if ($ctype !== 'credit' && $ctype !== 'debit') $ctype = 'debit';
            }
            $abs = abs($amount);
            if ($ctype === 'debit')  $amount = -$abs;
            if ($ctype === 'credit') $amount =  $abs;
        }

        if ($isNew) {
            // INSERT
            $cols = ['date','account_id','amount','description','notes'];
            $ph   = [':d', ':a', ':m', ':ds', ':n'];
            $p    = [':d'=>$date, ':a'=>$accId, ':m'=>$amount, ':ds'=>$desc, ':n'=>$notes];

            if ($trxHasUser) { $cols[]='user_id'; $ph[]=':u'; $p[':u']=$userId; }
            if ($trxHasCat)  { $cols[]='category_id'; $ph[]=':c'; $p[':c']=$catId ?: null; }
            if ($trxHasExCa) { $cols[]='exclude_from_ca'; $ph[]=':x'; $p[':x']=$exCa; }

            $sql = "INSERT INTO transactions(".implode(',', $cols).") VALUES (".implode(',', $ph).")";
            $pdo->prepare($sql)->execute($p);
            Util::addFlash('success', 'Transaction ajoutée.');
        } else {
            // UPDATE
            $sets = ['date=:d','account_id=:a','amount=:m','description=:ds','notes=:n'];
            $p    = [':d'=>$date, ':a'=>$accId, ':m'=>$amount, ':ds'=>$desc, ':n'=>$notes, ':id'=>$pid];

            if ($trxHasCat)  { $sets[]='category_id=:c'; $p[':c']=$catId ?: null; }
            if ($trxHasExCa) { $sets[]='exclude_from_ca=:x'; $p[':x']=$exCa; }

            $sql = "UPDATE transactions SET ".implode(',', $sets)." WHERE id=:id";
            if ($trxHasUser) { $sql .= " AND user_id=:u"; $p[':u'] = $userId; }
            $pdo->prepare($sql)->execute($p);
            Util::addFlash('success', 'Transaction mise à jour.');
        }

        // Retour au tableau, en conservant un filtre sur le compte
        $redir = 'index.php';
        if ($accId > 0) $redir .= '?account_id='.$accId;
        Util::redirect($redir);
    } catch (Throwable $e) {
        Util::addFlash('danger', $e->getMessage());
        Util::redirect('transaction_edit.php'.($isNew?('?new=1'.($data['account_id']?('&account_id='.(int)$data['account_id']):'')) : ('?id='.(int)$id)));
    }
    exit;
}

// Prépare valeurs de formulaire
$formId   = $isNew ? 0 : (int)$id;
$formDate = (string)($data['date'] ?? date('Y-m-d'));
$formAcc  = (int)($data['account_id'] ?? 0);
$formAmt  = (float)($data['amount'] ?? 0);
$formDesc = (string)($data['description'] ?? '');
$formNotes= (string)($data['notes'] ?? '');
$formCat  = (int)($data['category_id'] ?? 0);
$formExCa = (int)($data['exclude_from_ca'] ?? 0);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?= $isNew ? 'Nouvelle transaction' : 'Modifier transaction #'.$formId ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__.'/_nav.php'; ?>

<div class="container py-3">

  <?php foreach (App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="card shadow-sm">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <strong><?= $isNew ? 'Nouvelle transaction' : 'Modifier transaction' ?></strong>
      <a class="btn btn-sm btn-outline-secondary" href="index.php<?= $formAcc>0 ? ('?account_id='.$formAcc) : '' ?>">Retour</a>
    </div>
    <div class="card-body">
      <form method="post" class="row g-3" id="txForm">
        <?= App\Util::csrfInput() ?>
        <input type="hidden" name="id" value="<?= (int)$formId ?>">

        <div class="col-sm-4">
          <label class="form-label">Date</label>
          <input type="date" name="date" value="<?= h($formDate) ?>" class="form-control" required>
        </div>

        <div class="col-sm-8">
          <label class="form-label">Compte</label>
          <select name="account_id" class="form-select" required>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= $formAcc===(int)$a['id']?'selected':'' ?>>
                <?= h($a['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($trxHasCat): ?>
        <div class="col-sm-6">
          <label class="form-label">Catégorie</label>
          <select name="category_id" id="category_id" class="form-select">
            <option value="0" data-type="">—</option>
            <?php foreach ($categories as $c): ?>
              <?php $ctype = strtolower($c['type'] ?? 'debit'); if ($ctype!=='credit' && $ctype!=='debit') $ctype='debit'; ?>
              <option
                value="<?= (int)$c['id'] ?>"
                data-type="<?= h($ctype) ?>"
                <?= $formCat===(int)$c['id']?'selected':'' ?>
              >
                <?= h($c['name']) ?> (<?= $ctype==='credit'?'Crédit':'Débit' ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" id="catHint" style="display:none;"></div>
        </div>
        <?php endif; ?>

        <div class="col-sm-6">
          <label class="form-label">Montant (€)</label>
          <input type="text" name="amount" id="amount" value="<?= h(number_format($formAmt, 2, '.', '')) ?>" class="form-control" required>
        </div>

        <div class="col-12">
          <label class="form-label">Description</label>
          <input type="text" name="description" value="<?= h($formDesc) ?>" class="form-control">
        </div>

        <div class="col-12">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?= h($formNotes) ?></textarea>
        </div>

        <?php if ($trxHasExCa): ?>
        <div class="col-12">
          <label class="form-check">
            <input class="form-check-input" type="checkbox" name="exclude_from_ca" value="1" <?= $formExCa? 'checked':'' ?>>
            <span class="form-check-label">Exclure du chiffre d'affaires</span>
          </label>
        </div>
        <?php endif; ?>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary">Enregistrer</button>
          <a class="btn btn-outline-secondary" href="index.php<?= $formAcc>0 ? ('?account_id='.$formAcc) : '' ?>">Annuler</a>
        </div>
      </form>
    </div>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  // Applique le signe en fonction de la catégorie sélectionnée (côté UX)
  var cat = document.getElementById('category_id');
  var amount = document.getElementById('amount');
  var catHint = document.getElementById('catHint');
  if (cat && amount) {
    function applySign() {
      var opt = cat.options[cat.selectedIndex];
      var type = opt ? (opt.getAttribute('data-type') || '') : '';
      var v = amount.value.trim();
      if (!v) { // pas de valeur, juste afficher l'indication
        if (catHint) {
          if (type === 'debit') { catHint.textContent = 'Type: Débit (le montant sera enregistré en négatif)'; catHint.style.display='block'; }
          else if (type === 'credit') { catHint.textContent = 'Type: Crédit (le montant sera enregistré en positif)'; catHint.style.display='block'; }
          else { catHint.style.display='none'; }
        }
        return;
      }
      // normaliser point décimal
      v = v.replace(',', '.');
      var n = parseFloat(v);
      if (isNaN(n)) { return; }
      var abs = Math.abs(n);
      if (type === 'debit')  n = -abs;
      if (type === 'credit') n =  abs;
      amount.value = n.toFixed(2);
      if (catHint) {
        if (type === 'debit') { catHint.textContent = 'Type: Débit (appliqué)'; catHint.style.display='block'; }
        else if (type === 'credit') { catHint.textContent = 'Type: Crédit (appliqué)'; catHint.style.display='block'; }
        else { catHint.style.display='none'; }
      }
    }
    cat.addEventListener('change', applySign);
    // Au chargement, affiche l'indication si cat pré-sélectionnée
    applySign();
  }
})();
</script>
</body>
</html>