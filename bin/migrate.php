<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;

$dir = __DIR__ . '/../migrations';
if (!is_dir($dir)) {
    echo "Dossier migrations manquant, création...\n";
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Impossible de créer le dossier migrations.\n");
        exit(1);
    }
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
foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $v) {
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

$pdo->beginTransaction();
try {
    foreach ($pending as $version => $path) {
        echo "==> Migration $version ... ";
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Lecture impossible: $path");
        }
        $pdo->exec($sql);
        $ins = $pdo->prepare("INSERT INTO schema_migrations(version) VALUES(:v)");
        $ins->execute([':v' => $version]);
        echo "OK\n";
    }
    $pdo->commit();
    echo "Toutes les migrations ont été appliquées.\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERREUR migration: " . $e->getMessage() . "\n");
    exit(1);
}