<?php
declare(strict_types=1);

/**
 * Outil d'admin unique (temporaire).
 * - op=check     -> état bootstrap / PHP / DB
 * - op=sanitize  -> nettoie display_errors/startup & error_reporting(E_ALL) résiduels (créé .bak)
 * - op=repair    -> répare/crée la micro 'Micro'
 *
 * A supprimer après usage.
 */

header('Content-Type: text/plain; charset=UTF-8');
// Affichage OK ici (script admin), le bootstrap gérera le mode prod ensuite
@ini_set('display_errors','1');

// 1) Autoload Composer (essaie plusieurs chemins depuis /test/)
$autoloadFound = false;
$autoloadCandidates = [
  __DIR__.'/vendor/autoload.php',       // /test/vendor
  __DIR__.'/../vendor/autoload.php',    // /vendor (racine projet)  ← le plus probable
  __DIR__.'/../../vendor/autoload.php',
];
foreach ($autoloadCandidates as $p) {
  if (is_file($p)) { require $p; $autoloadFound = true; break; }
}
if (!$autoloadFound) {
  echo "Autoload introuvable. Emplacements testés:\n- ".implode("\n- ", $autoloadCandidates)."\n";
  exit(1);
}

// 2) Bootstrap applicatif (essaie plusieurs chemins)
$bootstrapFound = false;
$bootstrapCandidates = [
  __DIR__.'/config/bootstrap.php',      // /test/config/bootstrap.php (si vous l'avez mis là)
  __DIR__.'/../config/bootstrap.php',   // /config/bootstrap.php (racine projet)  ← le plus probable
  __DIR__.'/../../config/bootstrap.php',
];
foreach ($bootstrapCandidates as $p) {
  if (is_file($p)) { require_once $p; $bootstrapFound = true; break; }
}
if (!$bootstrapFound) {
  echo "Bootstrap introuvable. Emplacements testés:\n- ".implode("\n- ", $bootstrapCandidates)."\n";
  echo "Créez le fichier config/bootstrap.php à la racine du projet ou corrigez le chemin.\n";
  exit(1);
}

use App\{Util, Database};

Util::startSession();
Util::requireAuth();

function hasTable(PDO $pdo, string $t): bool {
  $st=$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
  $st->execute([':t'=>$t]); return (bool)$st->fetchColumn();
}
function hasCol(PDO $pdo, string $table, string $col): bool {
  $st=$pdo->prepare("PRAGMA table_info($table)"); $st->execute();
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (strcasecmp((string)$c['name'],$col)===0) return true;
  return false;
}

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

$op = $_GET['op'] ?? 'help';
echo "Admin tools — op={$op}\n\n";

switch ($op) {
  case 'check':
    echo "APP_ENV: ".(defined('APP_ENV')?APP_ENV:'(non défini)')."\n";
    echo "display_errors: ".ini_get('display_errors')."\n";
    echo "log_errors: ".ini_get('log_errors')."\n";
    echo "error_log: ".ini_get('error_log')."\n";
    echo "timezone: ".date_default_timezone_get()."\n";
    // Chemin DB
    try {
      $rows = $pdo->query("PRAGMA database_list")->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rows as $r) if ((string)$r['name']==='main') echo "DB file: ".$r['file']."\n";
    } catch (Throwable $e) { echo "PRAGMA database_list: ".$e->getMessage()."\n"; }
    echo "\nOK.\n";
    break;

  case 'sanitize':
    // Nettoyage des lignes de debug dans /test (hors vendor, tools, data, logs)
    $root = __DIR__;
    $skip = ['vendor','node_modules','data','assets','logs','.git','tools'];
    $patterns = [
      '~^\s*@?ini_set\(\s*[\'"]display_errors[\'"]\s*,\s*[\'"](1|On)[\'"]\s*\)\s*;\s*$~mi' => '',
      '~^\s*@?ini_set\(\s*[\'"]display_startup_errors[\'"]\s*,\s*[\'"](1|On)[\'"]\s*\)\s*;\s*$~mi' => '',
      '~^\s*error_reporting\(\s*E_ALL\s*\)\s*;\s*$~mi' => '',
    ];
    $it = new RecursiveIteratorIterator(
      new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn($cur,$k,$it) => !($it->hasChildren() && in_array($cur->getFilename(), $skip, true))
      ),
      RecursiveIteratorIterator::SELF_FIRST
    );
    $changed = 0;
    foreach ($it as $f) {
      if (!$f->isFile() || strtolower($f->getExtension())!=='php') continue;
      $p = $f->getPathname();
      $src = file_get_contents($p);
      $out = $src;
      foreach ($patterns as $rx=>$rep) $out = preg_replace($rx, $rep, $out);
      if ($out !== $src) { @copy($p, $p.'.bak'); file_put_contents($p, $out); echo "Modifié: $p\n"; $changed++; }
    }
    echo "\nNettoyage terminé. Fichiers modifiés: $changed\n";
    break;

  case 'repair':
    // Assure table micro + colonne name + une micro 'Micro' pour l'utilisateur
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
    $pdo->exec("UPDATE micro_enterprises SET name='Micro' WHERE name IS NULL OR TRIM(name)=''");
    $st = $pdo->prepare("SELECT id FROM micro_enterprises WHERE user_id=:u ORDER BY id DESC LIMIT 1");
    $st->execute([':u'=>$userId]);
    $mid = (int)$st->fetchColumn();
    if ($mid<=0) {
      $pdo->prepare("INSERT INTO micro_enterprises (name, user_id, created_at, declaration_period, versement_liberatoire) VALUES ('Micro', :u, date('now'), 'quarterly', 0)")
          ->execute([':u'=>$userId]);
      $mid = (int)$pdo->lastInsertId();
      echo "Micro créée (id=$mid).\n";
    } else {
      echo "Micro existante (id=$mid).\n";
    }
    echo "OK.\n";
    break;

  default:
    echo "Usage:\n";
    echo "- /test/admin_tools.php?op=check    => état bootstrap / PHP / DB\n";
    echo "- /test/admin_tools.php?op=sanitize => enlève display_errors/startup & error_reporting(E_ALL) résiduels (crée .bak)\n";
    echo "- /test/admin_tools.php?op=repair   => répare/crée la micro 'Micro'\n";
    break;
}