<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;

$dir = __DIR__ . '/../migrations';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$db  = new Database();
$pdo = $db->pdo();
$pdo->exec("PRAGMA foreign_keys = ON");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS schema_migrations (
    version TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT (datetime('now'))
  )
");

$applied = [];
$st = $pdo->query("SELECT version FROM schema_migrations");
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
    $applied[$v] = true;
}

$files = glob($dir . DIRECTORY_SEPARATOR . '*.sql');
natsort($files);

$pending = [];
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (!isset($applied[$version])) {
        $pending[$version] = $file;
    }
}

if (!$pending) {
    echo "Aucune migration à appliquer.\n";
    exit(0);
}

foreach ($pending as $version => $path) {
    echo "==> Migration $version ... ";
    $sql = file_get_contents($path);
    if ($sql === false) {
        echo "ERREUR lecture.\n";
        exit(1);
    }
    // Split grossière (sépare sur ; suivi d’un retour) pour exécuter plusieurs statements
    $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql)));
    $pdo->beginTransaction();
    try {
        foreach ($statements as $stmt) {
            if ($stmt === '') continue;
            try {
                $pdo->exec($stmt);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                // Ignorer erreurs idempotentes
                if (
                    str_contains($msg, 'already exists') ||
                    str_contains($msg, 'duplicate column name')
                ) {
                    // ignore
                } else {
                    throw $e;
                }
            }
        }
        $ins = $pdo->prepare("INSERT INTO schema_migrations(version) VALUES(:v)");
        $ins->execute([':v' => $version]);
        $pdo->commit();
        echo "OK\n";
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo "ERREUR migration: ".$e->getMessage()."\n";
        exit(1);
    }
}
echo "Toutes les migrations terminées.\n";