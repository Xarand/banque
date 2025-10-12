<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database};

ini_set('display_errors','1'); error_reporting(E_ALL);

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

/* Helpers */
function h(string $s): string { return App\Util::h($s); }
function hasTable(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
    $st->execute([':t'=>$table]); return (bool)$st->fetchColumn();
}
function hasCol(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("PRAGMA table_info($table)"); $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (strcasecmp((string)$c['name'],$col)===0) return true;
    return false;
}
function parseDate(?string $s): ?string {
    if (!$s) return null; $s=trim($s);
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~',$s)) return $s;
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~',$s,$m)) return sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[2],(int)$m[1]);
    return null;
}
function normType(?string $t): string {
    $t = strtolower(trim((string)$t));
    // retire accents de base
    $t = strtr($t, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
    // patterns acceptés comme "crédit"
    if ($t === 'credit' || str_starts_with($t,'cred') || str_starts_with($t,'recette') || str_starts_with($t,'revenu')) return 'credit';
    if ($t === 'debit' || str_starts_with($t,'deb') || str_starts_with($t,'depense') || str_starts_with($t,'charge')) return 'debit';
    return ($t==='credit') ? 'credit' : 'debit';
}
function fmt(float $n): string { return number_format($n, 2, ',', ' '); }

/* Schéma */
$hasAccounts  = hasTable($pdo,'accounts');
$hasTx        = hasTable($pdo,'transactions');
$hasCats      = hasTable($pdo,'categories');

$accHasUser   = $hasAccounts  && hasCol($pdo,'accounts','user_id');
$accHasMicro  = $hasAccounts  && hasCol($pdo,'accounts','micro_enterprise_id');

$txHasUser    = $hasTx        && hasCol($pdo,'transactions','user_id');
$txHasCat     = $hasTx        && hasCol($pdo,'transactions','category_id');
$txHasExcl    = $hasTx        && hasCol($pdo,'transactions','exclude_from_ca');
$txHasNotes   = $hasTx        && hasCol($pdo,'transactions','notes');
$txHasDesc    = $hasTx        && hasCol($pdo,'transactions','description');
$txHasDate    = $hasTx        && hasCol($pdo,'transactions','date');
$txHasAmount  = $hasTx        && hasCol($pdo,'transactions','amount');
$txHasAccId   = $hasTx        && hasCol($pdo,'transactions','account_id');

$catHasUser   = $hasCats      && hasCol($pdo,'categories','user_id');
$catHasType   = $hasCats      && hasCol($pdo,'categories','type'); // credit|debit

/* Transactions récurrentes: table */
function ensureRecurringTable(PDO $pdo): void {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS recurring_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        account_id INTEGER NOT NULL,
        category_id INTEGER,
        description TEXT,
        notes TEXT,
        amount REAL NOT NULL,
        frequency TEXT NOT NULL,               -- monthly|quarterly|yearly
        anchor_date TEXT NOT NULL,
        next_run_date TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT
      );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_recur_user_next ON recurring_transactions(user_id, next_run_date, active)");
}

/* Comptes */
$accounts = [];
$accMapIsMicro = [];
if ($hasAccounts) {
    $sql = "SELECT id, name".($accHasUser?", user_id":"").($accHasMicro?", micro_enterprise_id":"")." FROM accounts";
    $p = [];
    if ($accHasUser) { $sql .= " WHERE user_id=:u"; $p[':u']=$userId; }
    $sql .= " ORDER BY name ASC";
    $st = $pdo->prepare($sql); $st->execute($p);
    $accounts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($accounts as $a) $accMapIsMicro[(int)$a['id']] = $accHasMicro && !empty($a['micro_enterprise_id']);
}

/* Catégories (avec type normalisé) */
$categories = [];
$catTypeById = []; // id => 'credit'|'debit'
if ($hasCats) {
    $sql = "SELECT id, name".($catHasType?", COALESCE(NULLIF(type,''),'debit') AS type":"")." FROM categories";
    $p = [];
    if ($catHasUser) { $sql .= " WHERE user_id=:u"; $p[':u']=$userId; }
    $sql .= " ORDER BY name ASC";
    $st = $pdo->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $c) {
        $t = $catHasType ? normType((string)($c['type'] ?? 'debit')) : 'debit';
        $categories[] = ['id'=>(int)$c['id'], 'name'=>$c['name'], 'type'=>$t];
        $catTypeById[(int)$c['id']] = $t;
    }
}

/* Récupérer type de catégorie sécurisé */
function fetchCategoryType(PDO $pdo, int $cid, bool $catHasUser, int $userId, bool $catHasType): ?string {
    if ($cid <= 0 || !$catHasType) return null;
    $sql = "SELECT COALESCE(NULLIF(type,''),'debit') FROM categories WHERE id=:id";
    $p = [':id'=>$cid];
    if ($catHasUser) { $sql .= " AND user_id=:u"; $p[':u']=$userId; }
    $sql .= " LIMIT 1";
    $st = $pdo->prepare($sql); $st->execute($p);
    $t = $st->fetchColumn();
    return $t !== false ? normType((string)$t) : null;
}

/* Mode */
$isNew = isset($_GET['new']) || isset($_POST['new']);
$txId  = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
$row   = null;
if (!$isNew && $txId>0) {
    $sql = "SELECT * FROM transactions WHERE id=:id";
    $p = [':id'=>$txId];
    if ($txHasUser) { $sql .= " AND user_id=:u"; $p[':u']=$userId; }
    $st = $pdo->prepare($sql); $st->execute($p);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { Util::addFlash('danger','Transaction introuvable.'); Util::redirect('index.php'); }
}

/* POST */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        Util::checkCsrf();
        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['create','update'], true)) throw new RuntimeException('Action invalide.');

        $date = parseDate($_POST['date'] ?? '') ?? date('Y-m-d');
        $accountId = (int)($_POST['account_id'] ?? 0);
        $amount = (float)str_replace(',', '.', (string)($_POST['amount'] ?? 0));
        $description = trim((string)($_POST['description'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;

        if ($accountId<=0) throw new RuntimeException('Compte requis.');
        if (!$txHasAmount || !$txHasDate || !$txHasAccId) throw new RuntimeException('Schéma transactions incomplet.');

        // Compte + Micro ?
        $sqlA = "SELECT * FROM accounts WHERE id=:id";
        $pA = [':id'=>$accountId];
        if ($accHasUser) { $sqlA .= " AND user_id=:u"; $pA[':u']=$userId; }
        $stA = $pdo->prepare($sqlA); $stA->execute($pA);
        $accRow = $stA->fetch(PDO::FETCH_ASSOC);
        if (!$accRow) throw new RuntimeException('Compte introuvable.');
        $isMicroAcc = $accHasMicro && !empty($accRow['micro_enterprise_id']);

        // Ajuste le signe selon la catégorie (robuste, avec normalisation)
        if ($txHasCat && $categoryId) {
            $ctype = fetchCategoryType($pdo, $categoryId, $catHasUser, $userId, $catHasType) ?? 'debit';
            if ($ctype === 'debit') $amount = -abs($amount);
            elseif ($ctype === 'credit') $amount = abs($amount);
        }

        // Exclure du CA: seulement comptes Micro
        $exclude = 0;
        if ($txHasExcl && $isMicroAcc) $exclude = isset($_POST['exclude_from_ca']) ? 1 : 0;

        if ($action==='create') {
            $cols = ['date','amount','account_id']; $vals=[':d',':m',':acc']; $bind=[':d'=>$date,':m'=>$amount,':acc'=>$accountId];
            if ($txHasUser) { $cols[]='user_id'; $vals[]=':u'; $bind[':u']=$userId; }
            if ($txHasCat)  { $cols[]='category_id'; $vals[]=':cat'; $bind[':cat']=$categoryId; }
            if ($txHasDesc) { $cols[]='description'; $vals[]=':desc'; $bind[':desc']=$description; }
            if ($txHasNotes){ $cols[]='notes'; $vals[]=':notes'; $bind[':notes']=$notes; }
            if ($txHasExcl) { $cols[]='exclude_from_ca'; $vals[]=':ex'; $bind[':ex']=$exclude; }
            $sql = "INSERT INTO transactions(".implode(',',$cols).") VALUES (".implode(',',$vals).")";
            $pdo->prepare($sql)->execute($bind);

            // Récurrente
            if (isset($_POST['recurring'])) {
                $freq = (string)($_POST['frequency'] ?? 'monthly');
                if (!in_array($freq, ['monthly','quarterly','yearly'], true)) $freq='monthly';
                ensureRecurringTable($pdo);
                $anchor = $date; $next = $anchor;
                $i=0; while ($next <= $date && $i<6) {
                    $next = $freq==='monthly' ? date('Y-m-d', strtotime($next.' +1 month'))
                         : ($freq==='quarterly' ? date('Y-m-d', strtotime($next.' +3 month')) : date('Y-m-d', strtotime($next.' +1 year')));
                    $i++;
                }
                $pdo->prepare("
                  INSERT INTO recurring_transactions (user_id, account_id, category_id, description, notes, amount, frequency, anchor_date, next_run_date, active, created_at)
                  VALUES (:u,:acc,:cat,:desc,:notes,:amt,:freq,:anchor,:next,1,datetime('now'))
                ")->execute([
                    ':u'=>$userId, ':acc'=>$accountId, ':cat'=>$txHasCat ? ($categoryId ?: null) : null,
                    ':desc'=>$description, ':notes'=>$notes, ':amt'=>$amount, ':freq'=>$freq, ':anchor'=>$anchor, ':next'=>$next
                ]);
                Util::addFlash('success','Transaction créée (récurrente; prochaine: '.$next.').');
            } else {
                Util::addFlash('success','Transaction créée.');
            }
            $return = trim((string)($_POST['return'] ?? '')); Util::redirect($return !== '' ? ('index.php?'.$return) : 'index.php');

        } else {
            if ($txId<=0) throw new RuntimeException('ID invalide.');
            $sqlT = "SELECT account_id FROM transactions WHERE id=:id"; $pT=[':id'=>$txId];
            if ($txHasUser) { $sqlT .= " AND user_id=:u"; $pT[':u']=$userId; }
            $st = $pdo->prepare($sqlT); $st->execute($pT);
            if (!$st->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException('Transaction introuvable.');

            $sets=['date=:d','amount=:m','account_id=:acc']; $bind=[':d'=>$date,':m'=>$amount,':acc'=>$accountId,':id'=>$txId];
            if ($txHasCat)   { $sets[]='category_id=:cat'; $bind[':cat']=$categoryId ?: null; }
            if ($txHasDesc)  { $sets[]='description=:desc'; $bind[':desc']=$description; }
            if ($txHasNotes) { $sets[]='notes=:notes'; $bind[':notes']=$notes; }
            if ($txHasExcl)  { $sets[]='exclude_from_ca=:ex'; $bind[':ex']=$exclude; }
            $sql = "UPDATE transactions SET ".implode(', ',$sets)." WHERE id=:id";
            if ($txHasUser) { $sql .= " AND user_id=:u"; $bind[':u']=$userId; }
            $pdo->prepare($sql)->execute($bind);

            Util::addFlash('success','Transaction modifiée.');
            $return = trim((string)($_POST['return'] ?? '')); Util::redirect($return !== '' ? ('index.php?'.$return) : 'index.php');
        }
    } catch (Throwable $e) {
        Util::addFlash('danger', $e->getMessage());
        $return = trim((string)($_POST['return'] ?? ''));
        Util::redirect('transaction_edit.php'.($isNew?'?new=1':('?id='.$txId)).($return!==''?('&return='.$return):''));
    }
    exit;
}

/* Valeurs formulaire */
$valDate = $row['date'] ?? date('Y-m-d');
$valAcc  = isset($row['account_id']) ? (int)$row['account_id'] : (int)($_GET['account_id'] ?? 0);
$valCat  = isset($row['category_id']) ? (int)$row['category_id'] : 0;
$valAmt  = isset($row['amount']) ? (float)$row['amount'] : 0.0;
$valDesc = (string)($row['description'] ?? '');
$valNotes= (string)($row['notes'] ?? '');
$valExcl = $txHasExcl ? (int)($row['exclude_from_ca'] ?? 0) : 0;

$jsMapAcc  = $accMapIsMicro;
$jsMapType = $catTypeById;
$isMicroCurrent = $valAcc>0 ? (bool)($jsMapAcc[$valAcc] ?? false) : false;

// Retour
$returnQuery = trim((string)($_GET['return'] ?? $_POST['return'] ?? ''));
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?= $isNew ? 'Nouvelle transaction' : 'Éditer transaction' ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php if (is_file(__DIR__.'/_head_assets.php')) include __DIR__.'/_head_assets.php'; ?>
</head>
<body>
<?php if (is_file(__DIR__.'/_nav.php')) include __DIR__.'/_nav.php'; ?>

<div class="container py-3">
  <?php foreach (Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="card shadow-sm">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <strong><?= $isNew ? 'Nouvelle transaction' : 'Éditer la transaction' ?></strong>
      <a class="btn btn-sm btn-outline-secondary" href="<?= $returnQuery!=='' ? ('index.php?'.h($returnQuery)) : 'index.php' ?>">Retour</a>
    </div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <?= Util::csrfInput() ?>
        <input type="hidden" name="<?= $isNew?'new':'id' ?>" value="<?= $isNew?1:(int)$txId ?>">
        <input type="hidden" name="action" value="<?= $isNew?'create':'update' ?>">
        <?php if ($returnQuery!==''): ?><input type="hidden" name="return" value="<?= h($returnQuery) ?>"><?php endif; ?>

        <div class="col-md-3">
          <label class="form-label">Date</label>
          <input type="date" name="date" class="form-control" required value="<?= h($valDate) ?>">
        </div>

        <div class="col-md-5">
          <label class="form-label">Compte</label>
          <select name="account_id" id="account_id" class="form-select" required>
            <option value="">Choisir…</option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= $valAcc===(int)$a['id']?'selected':'' ?>>
                <?= h($a['name']) ?><?= ($accHasMicro && !empty($a['micro_enterprise_id'])) ? ' (Micro)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($hasCats && $txHasCat): ?>
        <div class="col-md-5">
          <label class="form-label">Catégorie</label>
          <div class="input-group">
            <select name="category_id" id="category_id" class="form-select">
              <option value="">—</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>" data-type="<?= h((string)$c['type']) ?>" <?= $valCat===(int)$c['id']?'selected':'' ?>>
                  <?= h($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="input-group-text" id="cat_type_badge">
              <?php
                $ct = $valCat ? ($catTypeById[$valCat] ?? '') : '';
                echo $ct === 'credit' ? 'Crédit' : ($ct === 'debit' ? 'Débit' : '—');
              ?>
            </span>
          </div>
          <div class="form-text">Choisissez d’abord la catégorie: le signe du montant s’ajuste.</div>
        </div>
        <?php endif; ?>

        <div class="col-md-3">
          <label class="form-label">Montant</label>
          <input type="number" step="0.01" name="amount" id="amount" class="form-control" required value="<?= h((string)$valAmt) ?>">
          <div class="form-text">Le signe est imposé par la catégorie (Débit/Crédit).</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Description</label>
          <input type="text" name="description" class="form-control" value="<?= h($valDesc) ?>">
        </div>

        <div class="col-12">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?= h($valNotes) ?></textarea>
        </div>

        <?php if ($txHasExcl): ?>
        <div class="col-12" id="excl_block" style="<?= $isMicroCurrent ? '' : 'display:none' ?>">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="exclude_from_ca" id="exclude_from_ca" <?= $valExcl? 'checked':'' ?>>
            <label class="form-check-label" for="exclude_from_ca">Exclure du chiffre d’affaires</label>
            <div class="form-text">Visible uniquement pour les comptes Micro.</div>
          </div>
        </div>
        <?php endif; ?>

        <hr class="mt-2 mb-0">

        <!-- Transaction récurrente -->
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="recurring" id="recurring">
            <label class="form-check-label" for="recurring">Enregistrer comme transaction récurrente</label>
          </div>
        </div>
        <div class="col-md-6" id="recurring_opts" style="display:none">
          <label class="form-label">Fréquence</label>
          <select name="frequency" class="form-select">
            <option value="monthly" selected>Mensuelle</option>
            <option value="quarterly">Trimestrielle</option>
            <option value="yearly">Annuelle</option>
          </select>
          <div class="form-text">La prochaine échéance sera calculée automatiquement à partir de la date.</div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary"><?= $isNew ? 'Créer' : 'Enregistrer' ?></button>
          <a class="btn btn-outline-secondary" href="<?= $returnQuery!=='' ? ('index.php?'.h($returnQuery)) : 'index.php' ?>">Annuler</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  var mapAccIsMicro = <?= json_encode($jsMapAcc, JSON_UNESCAPED_UNICODE) ?>;
  var mapCatType    = <?= json_encode($jsMapType, JSON_UNESCAPED_UNICODE) ?>;

  var selAcc = document.getElementById('account_id');
  var excl   = document.getElementById('excl_block');
  function toggleExcl(){
    if (!selAcc || !excl) return;
    var v = parseInt(selAcc.value||'0',10);
    excl.style.display = mapAccIsMicro[v] ? '' : 'none';
    if (!mapAccIsMicro[v]) {
      var chk = document.getElementById('exclude_from_ca');
      if (chk) chk.checked = false;
    }
  }
  if (selAcc) selAcc.addEventListener('change', toggleExcl);
  toggleExcl();

  // Auto-signe selon catégorie
  var selCat = document.getElementById('category_id');
  var amtEl  = document.getElementById('amount');
  var badge  = document.getElementById('cat_type_badge');

  function updateBadge(t){
    if (!badge) return;
    badge.textContent = (t === 'credit') ? 'Crédit' : (t === 'debit' ? 'Débit' : '—');
  }
  function adjustAmountSign(){
    if (!selCat || !amtEl) return;
    var cid = parseInt(selCat.value||'0',10);
    var t = mapCatType[cid] || '';
    updateBadge(t);
    var v = parseFloat((amtEl.value||'').toString().replace(',','.'));
    if (isNaN(v)) return;
    var a = Math.abs(v);
    if (t === 'debit') amtEl.value = (-a).toFixed(2);
    else if (t === 'credit') amtEl.value = (a).toFixed(2);
  }
  if (selCat) selCat.addEventListener('change', adjustAmountSign);
  // Au chargement: si catégorie déjà sélectionnée, ajuste le signe
  adjustAmountSign();

  // récurrente: options
  var sw = document.getElementById('recurring');
  var box = document.getElementById('recurring_opts');
  function t(){ if (box) box.style.display = (sw && sw.checked) ? '' : 'none'; }
  if (sw) { sw.addEventListener('change', t); t(); }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>