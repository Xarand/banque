<?php
declare(strict_types=1);

ini_set('display_errors','1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=UTF-8');

// 1) Autoload Composer: essaie plusieurs chemins depuis /test/tools/
$autoloadFound = false;
$candidates = [
  __DIR__.'/../../vendor/autoload.php',      // banque/vendor/autoload.php (le plus probable)
  __DIR__.'/../vendor/autoload.php',         // /test/vendor/autoload.php (si vendor sous /test)
  dirname(__DIR__,3).'/vendor/autoload.php', // au cas où
  dirname(__DIR__,2).'/vendor/autoload.php',
];
foreach ($candidates as $p) {
  if (is_file($p)) { require $p; $autoloadFound = true; break; }
}
if (!$autoloadFound) {
  http_response_code(500);
  echo "Autoload introuvable. Emplacements testés:\n- ".implode("\n- ", $candidates)."\n";
  echo "Solution: uploadez le dossier vendor/ à la racine de l'app ou corrigez le chemin.\n";
  exit;
}

use App\{Util, Database};

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

function hasTable(PDO $pdo, string $t): bool {
  $st=$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
  $st->execute([':t'=>$t]); return (bool)$st->fetchColumn();
}
function hasCol(PDO $pdo, string $table, string $col): bool {
  $st=$pdo->prepare("PRAGMA table_info($table)"); $st->execute();
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (strcasecmp((string)$c['name'],$col)===0) return true;
  return false;
}

// 2) Assure la table et la colonne name
if (!hasTable($pdo,'micro_enterprises')) {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS micro_enterprises (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
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
  echo "Table micro_enterprises créée.\n";
} else {
  if (!hasCol($pdo,'micro_enterprises','name')) {
    $pdo->exec("ALTER TABLE micro_enterprises ADD COLUMN name TEXT");
    echo "Colonne name ajoutée.\n";
  }
}

// 3) Corrige noms vides et crée une micro pour l'utilisateur si nécessaire
$pdo->exec("UPDATE micro_enterprises SET name='Micro' WHERE name IS NULL OR TRIM(name)=''");
echo "Noms vides corrigés.\n";

$sql = "SELECT id FROM micro_enterprises WHERE user_id=:u ORDER BY id DESC LIMIT 1";
$st = $pdo->prepare($sql); $st->execute([':u'=>$userId]);
$mid = (int)$st->fetchColumn();

if ($mid<=0) {
  $pdo->prepare("INSERT INTO micro_enterprises (name, user_id, created_at, declaration_period, versement_liberatoire) VALUES ('Micro', :u, date('now'), 'quarterly', 0)")
      ->execute([':u'=>$userId]);
  $mid = (int)$pdo->lastInsertId();
  echo "Micro créée pour l'utilisateur (id=$mid).\n";
} else {
  echo "Micro déjà présente (id=$mid).\n";
}

echo "OK.\n";