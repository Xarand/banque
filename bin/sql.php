<?php
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/sql.php \"REQUETE_SQL\" \n");
    exit(1);
}

$sql = $argv[1];
$dbFile = __DIR__ . '/../data/finance.db';
if(!file_exists($dbFile)){
    fwrite(STDERR, "Base introuvable: $dbFile\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $st = $pdo->query($sql);
    if ($st === false) {
        echo "Aucune sortie.\n";
        exit;
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "(0 ligne)\n";
        exit;
    }
    // Affichage simple colonnes + valeurs
    $cols = array_keys($rows[0]);
    echo implode("\t", $cols), PHP_EOL;
    foreach($rows as $r){
        $line = [];
        foreach($cols as $c){
            $line[] = (string)$r[$c];
        }
        echo implode("\t", $line), PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Erreur SQL: ".$e->getMessage()."\n");
    exit(2);
}