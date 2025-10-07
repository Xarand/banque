<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

class MicroEnterpriseRepository
{
    public function __construct(private PDO $pdo) {}

    public function createMicro(
        int $userId,
        string $name,
        ?string $regime=null,
        ?float $caCeiling=null,
        ?float $tvaCeiling=null,
        ?string $primary=null,
        ?string $secondary=null
    ): int {
        $name = trim($name);
        if ($name==='') {
            throw new RuntimeException("Nom requis.");
        }
        $st = $this->pdo->prepare("
          INSERT INTO micro_enterprises(user_id,name,regime,ca_ceiling,tva_ceiling,primary_color,secondary_color)
          VALUES(:u,:n,:r,:ca,:tva,:pc,:sc)
        ");
        $st->execute([
            ':u'=>$userId, ':n'=>$name, ':r'=>$regime ?: null,
            ':ca'=>$caCeiling, ':tva'=>$tvaCeiling,
            ':pc'=>$primary, ':sc'=>$secondary
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function listMicro(int $userId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM micro_enterprises WHERE user_id=:u ORDER BY name COLLATE NOCASE");
        $st->execute([':u'=>$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMicro(int $userId, int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM micro_enterprises WHERE id=:id AND user_id=:u LIMIT 1");
        $st->execute([':id'=>$id, ':u'=>$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function updateMicro(int $userId, int $id, array $data): void
    {
        $fields=['name','regime','ca_ceiling','tva_ceiling','primary_color','secondary_color'];
        $sets=[]; $params=[':id'=>$id, ':u'=>$userId];
        foreach($fields as $f){
            if(array_key_exists($f,$data)){
                $sets[]="$f=:$f";
                $params[":$f"] = $data[$f] !== '' ? $data[$f] : null;
            }
        }
        if(!$sets) return;
        $sql="UPDATE micro_enterprises SET ".implode(',', $sets).", updated_at=datetime('now') WHERE id=:id AND user_id=:u";
        $st=$this->pdo->prepare($sql);
        $st->execute($params);
        if($st->rowCount()===0) throw new RuntimeException("Mise à jour micro entreprise non effectuée.");
    }

    public function createMicroCategory(int $userId, int $microId, string $name, string $type): int
    {
        $this->assertMicroOwnership($userId,$microId);
        $name=trim($name);
        if($name==='') throw new RuntimeException("Nom requis.");
        if(!in_array($type,['income','expense'],true)) throw new RuntimeException("Type invalide.");
        $st=$this->pdo->prepare("
          INSERT INTO micro_enterprise_categories(micro_id,name,type)
          VALUES(:m,:n,:t)
        ");
        $st->execute([':m'=>$microId, ':n'=>$name, ':t'=>$type]);
        return (int)$this->pdo->lastInsertId();
    }

    public function listMicroCategories(int $userId, int $microId): array
    {
        $this->assertMicroOwnership($userId,$microId);
        $st=$this->pdo->prepare("SELECT * FROM micro_enterprise_categories WHERE micro_id=:m ORDER BY name COLLATE NOCASE");
        $st->execute([':m'=>$microId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function attachAccount(int $userId, int $microId, int $accountId): void
    {
        $this->assertMicroOwnership($userId,$microId);
        $c=$this->pdo->prepare("SELECT id FROM accounts WHERE id=:a AND user_id=:u");
        $c->execute([':a'=>$accountId, ':u'=>$userId]);
        if(!$c->fetchColumn()) throw new RuntimeException("Compte introuvable.");
        $up=$this->pdo->prepare("UPDATE accounts SET micro_enterprise_id=:m WHERE id=:a");
        $up->execute([':m'=>$microId, ':a'=>$accountId]);
    }

    public function detachAccount(int $userId, int $accountId): void
    {
        $c=$this->pdo->prepare("SELECT id FROM accounts WHERE id=:a AND user_id=:u");
        $c->execute([':a'=>$accountId, ':u'=>$userId]);
        if(!$c->fetchColumn()) throw new RuntimeException("Compte introuvable.");
        $up=$this->pdo->prepare("UPDATE accounts SET micro_enterprise_id=NULL WHERE id=:a");
        $up->execute([':a'=>$accountId]);
    }

    public function getMicroOverview(int $userId, int $microId, ?int $year=null): array
    {
        $this->assertMicroOwnership($userId,$microId);
        $year = $year ?: (int)date('Y');
        $start = sprintf('%d-01-01',$year);
        $end   = sprintf('%d-12-31',$year);

        $sql = "
          SELECT
            SUM(CASE WHEN t.amount>=0 THEN t.amount ELSE 0 END) AS credits,
            SUM(CASE WHEN t.amount<0 THEN -t.amount ELSE 0 END) AS debits,
            SUM(t.amount) AS net
          FROM transactions t
          JOIN accounts a ON a.id=t.account_id
          WHERE a.micro_enterprise_id=:m
            AND a.user_id=:u
            AND date(t.date) BETWEEN date(:s) AND date(:e)
        ";
        $st=$this->pdo->prepare($sql);
        $st->execute([':m'=>$microId, ':u'=>$userId, ':s'=>$start, ':e'=>$end]);
        $row=$st->fetch(PDO::FETCH_ASSOC) ?: ['credits'=>0,'debits'=>0,'net'=>0];

        $sqlM="
          SELECT strftime('%Y-%m', t.date) AS ym,
                 SUM(CASE WHEN t.amount>=0 THEN t.amount ELSE 0 END) AS credits,
                 SUM(CASE WHEN t.amount<0 THEN -t.amount ELSE 0 END) AS debits,
                 SUM(t.amount) AS net
          FROM transactions t
          JOIN accounts a ON a.id=t.account_id
          WHERE a.micro_enterprise_id=:m
            AND a.user_id=:u
            AND date(t.date) BETWEEN date(:s) AND date(:e)
          GROUP BY ym
          ORDER BY ym
        ";
        $stm=$this->pdo->prepare($sqlM);
        $stm->execute([':m'=>$microId, ':u'=>$userId, ':s'=>$start, ':e'=>$end]);
        $monthly=$stm->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $micro=$this->getMicro($userId,$microId);
        $caCeil = $micro['ca_ceiling'] ?? null;
        $tvaCeil = $micro['tva_ceiling'] ?? null;
        $credits = (float)$row['credits'];
        $caUsagePct  = $caCeil ? min(100, round($credits / $caCeil * 100, 2)) : null;
        $tvaUsagePct = $tvaCeil ? min(100, round($credits / $tvaCeil * 100, 2)) : null;

        return [
            'year'=>$year,
            'credits'=>(float)$row['credits'],
            'debits'=>(float)$row['debits'],
            'net'=>(float)$row['net'],
            'monthly'=>$monthly,
            'ca_ceiling'=>$caCeil,
            'tva_ceiling'=>$tvaCeil,
            'ca_usage_pct'=>$caUsagePct,
            'tva_usage_pct'=>$tvaUsagePct
        ];
    }

    private function assertMicroOwnership(int $userId, int $microId): void
    {
        $st=$this->pdo->prepare("SELECT 1 FROM micro_enterprises WHERE id=:m AND user_id=:u");
        $st->execute([':m'=>$microId, ':u'=>$userId]);
        if(!$st->fetchColumn()) {
            throw new RuntimeException("Micro-entreprise introuvable.");
        }
    }
}