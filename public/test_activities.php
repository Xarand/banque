<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
$pdo = (new App\Database())->pdo();
$rows = $pdo->query("SELECT code,label FROM micro_activity_rates ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type:text/plain; charset=utf-8');
echo "Count=".count($rows).PHP_EOL;
foreach ($rows as $r) {
    $ok = mb_check_encoding($r['label'],'UTF-8') ? 'OK' : 'BAD';
    echo $r['code'].' '.$ok.' '.$r['label'].PHP_EOL;
}