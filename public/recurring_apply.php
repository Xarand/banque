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

/* Helpers */
function hasTable(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
    $st->execute([':t'=>$table]); return (bool)$st->fetchColumn();
}
function hasCol(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("PRAGMA table_info($table)"); $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (strcasecmp((string)$c['name'], $col)===0) return true;
    return false;
}
function nextDate(string $date, string $freq): string {
    if ($freq==='monthly')    return date('Y-m-d', strtotime($date.' +1 month'));
    if ($freq==='quarterly')  return date('Y-m-d', strtotime($date.' +3 month'));
    return date('Y-m-d', strtotime($date.' +1 year')); // yearly
}

/* Schéma */
if (!hasTable($pdo,'recurring_transactions')) {
    Util::addFlash('info','Aucune transaction récurrente configurée.');
    Util::redirect('index.php'); exit;
}
$txHasUser   = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','user_id');
$txHasCat    = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','category_id');
$txHasDesc   = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','description');
$txHasNotes  = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','notes');
$txHasExcl   = hasTable($pdo,'transactions') && hasCol($pdo,'transactions','exclude_from_ca');

/* Sélectionne les règles dues (aujourd’hui ou avant), actives et à vous */
$today = date('Y-m-d');
$st = $pdo->prepare("SELECT * FROM recurring_transactions WHERE user_id=:u AND active=1 AND date(next_run_date) <= date(:d) ORDER BY next_run_date ASC");
$st->execute([':u'=>$userId, ':d'=>$today]);
$rules = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!$rules) {
    Util::addFlash('info','Aucune transaction récurrente due aujourd’hui.');
    Util::redirect('index.php'); exit;
}

/* Applique */
$inserted = 0;
$pdo->beginTransaction();
try {
    foreach ($rules as $r) {
        $date = (string)$r['next_run_date'];
        $freq = (string)$r['frequency'];
        $next = nextDate($date, $freq);

        // Insère la transaction
        $cols=['date','amount','account_id']; $vals=[':d',':m',':acc']; $bind=[':d'=>$date,':m'=>(float)$r['amount'],':acc'=>(int)$r['account_id']];
        if ($txHasUser)  { $cols[]='user_id'; $vals[]=':u'; $bind[':u']=$userId; }
        if ($txHasCat)   { $cols[]='category_id'; $vals[]=':cat'; $bind[':cat']= $r['category_id']!==null ? (int)$r['category_id'] : null; }
        if ($txHasDesc)  { $cols[]='description'; $vals[]=':desc'; $bind[':desc']=(string)$r['description']; }
        if ($txHasNotes) { $cols[]='notes'; $vals[]=':notes'; $bind[':notes']=(string)$r['notes']; }
        if ($txHasExcl)  { $cols[]='exclude_from_ca'; $vals[]=':ex'; $bind[':ex']=0; } // valeur neutre par défaut

        $sql = "INSERT INTO transactions(".implode(',',$cols).") VALUES (".implode(',',$vals).")";
        $pdo->prepare($sql)->execute($bind);
        $inserted++;

        // Programme la prochaine occurrence
        $pdo->prepare("UPDATE recurring_transactions SET next_run_date=:n WHERE id=:id AND user_id=:u")
            ->execute([':n'=>$next, ':id'=>(int)$r['id'], ':u'=>$userId]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    Util::addFlash('danger','Erreur lors de l’application des récurrentes: '.$e->getMessage());
    Util::redirect('index.php'); exit;
}

Util::addFlash('success', $inserted.' transaction(s) récurrente(s) appliquée(s).');
Util::redirect('index.php');