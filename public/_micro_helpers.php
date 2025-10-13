<?php
declare(strict_types=1);

function mh_hasTable(PDO $pdo, string $t): bool {
    $st=$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
    $st->execute([':t'=>$t]); return (bool)$st->fetchColumn();
}
function mh_hasCol(PDO $pdo, string $table, string $col): bool {
    $st=$pdo->prepare("PRAGMA table_info($table)"); $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (strcasecmp((string)$c['name'],$col)===0) return true;
    return false;
}

/**
 * Garantit qu'une micro-entreprise existe et retourne son ID.
 */
function ensureMicroForUser(PDO $pdo, int $userId, string $defaultName='Micro'): int {
    if (!mh_hasTable($pdo,'micro_enterprises')) {
        throw new RuntimeException("Table micro_enterprises manquante.");
    }
    $hasUser = mh_hasCol($pdo,'micro_enterprises','user_id');
    if (!mh_hasCol($pdo,'micro_enterprises','name')) {
        $pdo->exec("ALTER TABLE micro_enterprises ADD COLUMN name TEXT");
    }
    $pdo->exec("UPDATE micro_enterprises SET name='Micro' WHERE name IS NULL OR TRIM(name)=''");

    if ($hasUser) {
        $st = $pdo->prepare("SELECT id FROM micro_enterprises WHERE user_id=:u ORDER BY id DESC LIMIT 1");
        $st->execute([':u'=>$userId]);
        $id = (int)$st->fetchColumn();
        if ($id>0) return $id;
    } else {
        $id = (int)$pdo->query("SELECT id FROM micro_enterprises ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($id>0) return $id;
    }

    $cols=['name','created_at']; $vals=[':n',"datetime('now')"]; $bind=[':n'=>$defaultName];
    if ($hasUser){ $cols[]='user_id'; $vals[]=':u'; $bind[':u']=$userId; }
    $sql="INSERT INTO micro_enterprises(".implode(',',$cols).") VALUES (".implode(',',$vals).")";
    $pdo->prepare($sql)->execute($bind);
    return (int)$pdo->lastInsertId();
}