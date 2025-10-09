<?php
$path = __DIR__ . '/../data/finance.db';
if(!file_exists($path)){
    fwrite(STDERR,"Base introuvable: $path\n");
    exit(1);
}
$pdo = new PDO('sqlite:'.$path);
$cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
echo "Colonnes de users:\n";
foreach($cols as $c){
    echo "- {$c['name']} ({$c['type']})\n";
}
