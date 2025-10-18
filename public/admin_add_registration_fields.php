<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__.'/../config/bootstrap.php';

use App\Database;

header('Content-Type: text/plain; charset=utf-8');

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function hasCol(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->query("PRAGMA table_info($table)");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (strcasecmp((string)$c['name'], $col) === 0) return true;
    }
    return false;
}

echo "=== Migration: ajout colonnes inscription ===\n";

if (!hasCol($pdo,'users','department')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN department TEXT NULL");
    echo "- colonne users.department ajoutée\n";
} else {
    echo "- colonne users.department déjà présente\n";
}

if (!hasCol($pdo,'users','activity')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN activity TEXT NULL");
    echo "- colonne users.activity ajoutée\n";
} else {
    echo "- colonne users.activity déjà présente\n";
}

if (!hasCol($pdo,'users','cma_rate')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN cma_rate REAL NULL");
    echo "- colonne users.cma_rate ajoutée\n";
} else {
    echo "- colonne users.cma_rate déjà présente\n";
}

if (!hasCol($pdo,'users','email_verified')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN email_verified INTEGER NOT NULL DEFAULT 0");
    echo "- colonne users.email_verified ajoutée\n";
} else {
    echo "- colonne users.email_verified déjà présente\n";
}

if (!hasCol($pdo,'users','verify_token')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN verify_token TEXT NULL");
    echo "- colonne users.verify_token ajoutée\n";
} else {
    echo "- colonne users.verify_token déjà présente\n";
}

echo "\nTerminé. Supprimez ce script après exécution.\n";