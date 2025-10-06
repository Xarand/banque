<?php
declare(strict_types=1);

session_start();
echo "SESSION:\n";
var_dump($_SESSION);

$path = realpath(__DIR__ . '/../data/finance.db');
echo "DB path: $path\n";

if (!$path || !file_exists($path)) {
    echo "Base introuvable.\n";
    exit;
}

$pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "\nUtilisateurs existants:\n";
$users = $pdo->query("SELECT id,email FROM users ORDER BY id")->fetchAll();
if (!$users) {
    echo "(aucun)\n";
} else {
    foreach ($users as $u) {
        echo "- {$u['id']} {$u['email']}\n";
    }
}