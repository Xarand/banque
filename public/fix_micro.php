<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
use App\Database;

ini_set('display_errors','1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=UTF-8');

try {
  $pdo = (new Database())->pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
  http_response_code(500); echo "Connexion DB impossible: ".$e->getMessage()."\n"; exit;
}

function hasTable(PDO $pdo, string $t): bool {
  $st=$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
  $st->execute([':t'=>$t]); return (bool)$st->fetchColumn();
}
function hasCol(PDO $pdo, string $table, string $col): bool {
  $st=$pdo->prepare("PRAGMA table_info($table)"); $st->execute();
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (strcasecmp((string)$c['name'],$col)===0) return true;
  return false;
}

if (!hasTable($pdo,'micro_enterprises')) { http_response_code(500); echo "Table micro_enterprises introuvable.\n"; exit; }
$hasName = hasCol($pdo,'micro_enterprises','name');
if (!$hasName) { $pdo->exec("ALTER TABLE micro_enterprises ADD COLUMN name TEXT"); }

$pdo->exec("UPDATE micro_enterprises SET name='Micro' WHERE name IS NULL OR TRIM(name)=''");

$id = (int)$pdo->query("SELECT id FROM micro_enterprises ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($id<=0) {
  $pdo->exec("INSERT INTO micro_enterprises(name, created_at) VALUES ('Micro', datetime('now'))");
  $id = (int)$pdo->lastInsertId();
}

echo "OK — micro_enterprises prête. ID Micro = $id\n";
echo "Vous pouvez supprimer ce fichier fix_micro.php.\n";