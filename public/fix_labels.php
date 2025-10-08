<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\Database;

$pdo = (new Database())->pdo();

// Liste
$rows = $pdo->query("SELECT id,code,label FROM micro_activity_rates ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

echo "<pre>";
$updated = 0;
foreach ($rows as $r) {
    $lab = $r['label'];
    $ok  = mb_check_encoding($lab,'UTF-8');
    if ($ok) {
        echo $r['code']." OK (UTF-8)\n";
        continue;
    }
    // Conversion CP1252 -> UTF-8
    $fixed = @iconv('CP1252','UTF-8//IGNORE',$lab);
    if ($fixed && $fixed !== $lab) {
        $st = $pdo->prepare("UPDATE micro_activity_rates SET label=:l WHERE id=:id");
        $st->execute([':l'=>$fixed, ':id'=>$r['id']]);
        echo $r['code']." FIX => $fixed\n";
        $updated++;
    } else {
        echo $r['code']." IMPOSSIBLE (reste inchangé)\n";
    }
}
echo "\nTerminé. $updated corrections.\n</pre>";