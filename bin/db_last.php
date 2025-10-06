<?php
declare(strict_types=1);

$path = __DIR__ . '/../data/finance.db';
if (!is_file($path)) {
    fwrite(STDERR, "Base introuvable: $path\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$row = $pdo->query("SELECT id, date, amount, description, notes, category_id, account_id
                    FROM transactions ORDER BY id DESC LIMIT 1")->fetch();

if (!$row) {
    echo "Aucune transaction.\n";
    exit;
}
var_export($row);
echo PHP_EOL;