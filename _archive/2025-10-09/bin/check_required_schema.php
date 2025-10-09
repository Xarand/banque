<?php
$required = [
  'users' => ['id','email','password_hash','display_name','failed_logins','last_login_at','created_at','updated_at'],
];
$path = __DIR__ . '/../data/finance.db';
$pdo = new PDO('sqlite:'.$path);
foreach($required as $table=>$cols){
    $existing = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(fn($c)=>$c['name'],$existing);
    $missing = array_diff($cols,$names);
    echo $table, ': ', ($missing ? 'MANQUANT: '.implode(', ',$missing) : 'OK'), PHP_EOL;
}