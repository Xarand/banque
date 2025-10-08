<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\Database;

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Trouver users avec >1 micro
$dups = $pdo->query("
  SELECT user_id, GROUP_CONCAT(id) ids, COUNT(*) c
  FROM micro_enterprises
  GROUP BY user_id
  HAVING c > 1
")->fetchAll(PDO::FETCH_ASSOC);

if(!$dups){
    echo "Aucun doublon.\n";
    exit;
}

foreach($dups as $d){
    $userId = (int)$d['user_id'];
    $ids = array_map('intval', explode(',',$d['ids']));
    // Stratégie : garder le plus petit id
    sort($ids);
    $keep = array_shift($ids);
    echo "User $userId : garder micro $keep, fusion ".implode(',',$ids)."\n";

    foreach($ids as $oldId){
        // Rattacher comptes
        $u1 = $pdo->prepare("UPDATE accounts SET micro_enterprise_id=:keep WHERE micro_enterprise_id=:old");
        $u1->execute([':keep'=>$keep, ':old'=>$oldId]);

        // Si le 'keep' n'a pas certains plafonds mais l'ancien oui -> compléter
        $rowKeep = $pdo->query("SELECT * FROM micro_enterprises WHERE id=$keep")->fetch(PDO::FETCH_ASSOC);
        $rowOld  = $pdo->query("SELECT * FROM micro_enterprises WHERE id=$oldId")->fetch(PDO::FETCH_ASSOC);

        $fields = ['ca_ceiling','tva_ceiling','activity_code','contributions_frequency','ir_liberatoire','creation_date','region','acre_reduction_rate'];
        $updates = [];
        foreach($fields as $f){
            if(($rowKeep[$f] ?? null) === null && ($rowOld[$f] ?? null) !== null){
                $val = $rowOld[$f];
                $updates[] = "$f=".($val===null ? "NULL" : "'".str_replace("'","''",$val)."'");
            }
        }
        if($updates){
            $pdo->exec("UPDATE micro_enterprises SET ".implode(',',$updates)." WHERE id=$keep");
        }

        // Supprimer ancienne micro
        $pdo->exec("DELETE FROM micro_enterprises WHERE id=$oldId");
    }
}
echo "Fusion terminée.\n";