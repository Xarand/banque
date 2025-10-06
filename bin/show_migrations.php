<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

$path = __DIR__ . '/../data/finance.db';
if (!is_file($path)) {
    fwrite(STDERR, "Base introuvable: $path\n");
    exit(1);
}
$pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$rows = $pdo->query("SELECT version, applied_at FROM schema_migrations ORDER BY version")->fetchAll();
if (!$rows) {
    echo "Aucune migration enregistrée.\n";
    exit;
}
foreach ($rows as $r) {
    echo $r['version'], " (", $r['applied_at'], ")\n";
}