<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
$pdo = (new App\Database())->pdo();
$rows = $pdo->query("SELECT code,label FROM micro_activity_rates ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type:text/plain; charset=utf-8');
foreach ($rows as $r) {
    $ok = mb_check_encoding($r['label'], 'UTF-8') ? 'OK' : 'BAD';
    printf("%s | %s | %s\n", $r['code'], $ok, $r['label']);
    if ($ok === 'BAD') {
        printf("  HEX: %s\n", bin2hex($r['label']));
    }
}