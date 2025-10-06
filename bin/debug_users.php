<?php
$path = __DIR__ . '/../data/finance.db';
$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rows = $pdo->query("SELECT id,email,created_at FROM users ORDER BY id")->fetchAll();
var_export($rows);
echo PHP_EOL;