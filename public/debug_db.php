<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\Database;

$pdo = (new Database())->pdo();

// Chemin de fichier ouvert par SQLite
$info = $pdo->query("PRAGMA database_list")->fetchAll(PDO::FETCH_ASSOC);
$path = $info[0]['file'] ?? '???';

// Nombre de barèmes
$count = (int)$pdo->query("SELECT COUNT(*) FROM micro_activity_rates")->fetchColumn();

// Codes listés
$codes = $pdo->query("SELECT code FROM micro_activity_rates ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);

header('Content-Type: text/plain; charset=utf-8');
echo "DB FILE : $path\n";
echo "NB RATES: $count\n";
echo "CODES   : ".implode(', ',$codes)."\n";