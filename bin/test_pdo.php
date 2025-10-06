<?php
$path = __DIR__ . '/../data/finance.db';
if (!file_exists($path)) {
    echo "DB introuvable: $path", PHP_EOL;
    exit(1);
}
$pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
var_dump($pdo instanceof PDO);
