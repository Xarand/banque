<?php
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/db_query.php \"SELECT ...\"\n");
    exit(1);
}
$sql = $argv[1];
$path = __DIR__ . '/../data/finance.db';
if (!file_exists($path)) {
    fwrite(STDERR, "DB missing: $path\n");
    exit(1);
}
$pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
foreach ($pdo->query($sql) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE), PHP_EOL;
}