<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use PDO;
use RuntimeException;

$dir = realpath(__DIR__ . '/../migrations');
if (!$dir) {
    fwrite(STDERR, "Dossier migrations introuvable.\n");
    exit(1);
}

$db = new Database();
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
    $base = basename($file);
    $version = preg_replace('/\.sql$/', '', $base);
    if (!isset($applied[$version])) {
        $pending[$version] = $file;
    }
}

if (!$pending) {
    echo "Aucune migration à appliquer.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    foreach ($pending as $version => $path) {
        echo "==> Migration $version ... ";
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Lecture impossible: $path");
        }
        $pdo->exec($sql);
        $ins = $pdo->prepare("INSERT INTO schema_migrations(version) VALUES(:v)");
        $ins->execute([':v' => $version]);
        echo "OK\n";
    }
    $pdo->commit();
    echo "Toutes les migrations ont été appliquées.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERREUR migration: " . $e->getMessage() . "\n");
    exit(1);
}