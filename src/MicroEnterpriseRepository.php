<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use DateTimeImmutable;

class MicroEnterpriseRepository
{
    public function __construct(private PDO $pdo) {}

    /* ========== CRUD de base (inchangé + enrichi) ========== */
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
        $fields=[
            'name','regime','ca_ceiling','tva_ceiling','primary_color','secondary_color',
            'activity_code','contributions_frequency','ir_liberatoire','creation_date','region','acre_reduction_rate'
        ];
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
        if($st->rowCount()===0) {
            // pas forcément erreur si données identiques
        }
    }

    /* ========== Catégories micro (inchangé minimal) ========== */
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

    /* ========== Rattachement comptes ========== */
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
        $c=$this->pdo->prepare("SELECT micro_enterprise_id FROM accounts WHERE id=:a AND user_id=:u");
        $c->execute([':a'=>$accountId, ':u'=>$userId]);
        if(!$c->fetchColumn() && $c->fetchColumn()!==0) throw new RuntimeException("Compte introuvable.");
        $up=$this->pdo->prepare("UPDATE accounts SET micro_enterprise_id=NULL WHERE id=:a");
        $up->execute([':a'=>$accountId]);
    }

    /* ========== CA & périodes ========== */
    public function computeYearToDate(int $userId, int $microId, int $year): array
    {
        $this->assertMicroOwnership($userId,$microId);
        $start = "$year-01-01";
        $end   = "$year-12-31";
        $sql = "
          SELECT
            SUM(CASE WHEN t.amount>=0 AND t.exclude_from_ca=0 THEN t.amount ELSE 0 END) AS ca,
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
        $row=$st->fetch(PDO::FETCH_ASSOC) ?: ['ca'=>0,'debits'=>0,'net'=>0];
        return [
            'ca'=>(float)$row['ca'],
            'debits'=>(float)$row['debits'],
            'net'=>(float)$row['net']
        ];
    }

    public function listContributionPeriods(int $userId, int $microId): array
    {
        $this->assertMicroOwnership($userId,$microId);
        $st=$this->pdo->prepare("
          SELECT * FROM micro_contribution_periods
          WHERE micro_id=:m
          ORDER BY period_start DESC
        ");
        $st->execute([':m'=>$microId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markPeriodPaid(int $userId, int $periodId): void
    {
        // vérifier appartenance
        $st=$this->pdo->prepare("
          SELECT p.id, me.user_id
            FROM micro_contribution_periods p
            JOIN micro_enterprises me ON me.id=p.micro_id
           WHERE p.id=:id LIMIT 1
        ");
        $st->execute([':id'=>$periodId]);
        $row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row || (int)$row['user_id']!==$userId){
            throw new RuntimeException("Période introuvable.");
        }
        $up=$this->pdo->prepare("UPDATE micro_contribution_periods SET status='paid', paid_at=date('now'), updated_at=datetime('now') WHERE id=:id");
        $up->execute([':id'=>$periodId]);
    }

    /**
     * Génère ou recalcule la période couvrant la date donnée + s'assure que les périodes précédentes existent
     * (simple : on génère uniquement la période de la date en cours si inexistante ou si pending recalcul)
     */
    public function generateOrRefreshCurrentPeriod(int $userId, int $microId, \DateTimeInterface $refDate): void
    {
        $micro = $this->getMicro($userId,$microId);
        if(!$micro) throw new RuntimeException("Micro introuvable.");
        $freq = $micro['contributions_frequency'] ?: 'quarterly';

        // Déterminer intervalle
        $period = $this->resolvePeriodBounds($refDate, $freq);
        $periodKey = $period['key'];

        // Existe ?
        $st=$this->pdo->prepare("SELECT * FROM micro_contribution_periods WHERE micro_id=:m AND period_key=:k LIMIT 1");
        $st->execute([':m'=>$microId, ':k'=>$periodKey]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);

        // Calcul des montants
        $computed = $this->computeContributionsForRange($userId,$micro,$period['start'],$period['end']);

        if(!$existing){
            $ins=$this->pdo->prepare("
              INSERT INTO micro_contribution_periods
              (micro_id,period_key,period_start,period_end,due_date,ca_amount,
               social_rate_used,social_due,ir_rate_used,ir_due,cfp_rate_used,cfp_due,
               chamber_type,chamber_rate_used,chamber_due,total_due,status)
              VALUES
              (:m,:k,:ps,:pe,:due,:ca,:sr,:sd,:ir,:id,:cr,:cd,:ct,:chr,:chd,:tot,'pending')
            ");
            $ins->execute([
                ':m'=>$microId,
                ':k'=>$periodKey,
                ':ps'=>$period['start'],
                ':pe'=>$period['end'],
                ':due'=>$period['due'],
                ':ca'=>$computed['ca'],
                ':sr'=>$computed['social_rate'],
                ':sd'=>$computed['social_due'],
                ':ir'=>$computed['ir_rate'],
                ':id'=>$computed['ir_due'],
                ':cr'=>$computed['cfp_rate'],
                ':cd'=>$computed['cfp_due'],
                ':ct'=>$computed['chamber_type'],
                ':chr'=>$computed['chamber_rate'],
                ':chd'=>$computed['chamber_due'],
                ':tot'=>$computed['total_due']
            ]);
        } else {
            if($existing['status']==='pending'){
                $up=$this->pdo->prepare("
                  UPDATE micro_contribution_periods
                     SET ca_amount=:ca,
                         social_rate_used=:sr,
                         social_due=:sd,
                         ir_rate_used=:ir,
                         ir_due=:id,
                         cfp_rate_used=:cr,
                         cfp_due=:cd,
                         chamber_type=:ct,
                         chamber_rate_used=:chr,
                         chamber_due=:chd,
                         total_due=:tot,
                         updated_at=datetime('now')
                   WHERE id=:idp
                ");
                $up->execute([
                    ':ca'=>$computed['ca'],
                    ':sr'=>$computed['social_rate'],
                    ':sd'=>$computed['social_due'],
                    ':ir'=>$computed['ir_rate'],
                    ':id'=>$computed['ir_due'],
                    ':cr'=>$computed['cfp_rate'],
                    ':cd'=>$computed['cfp_due'],
                    ':ct'=>$computed['chamber_type'],
                    ':chr'=>$computed['chamber_rate'],
                    ':chd'=>$computed['chamber_due'],
                    ':tot'=>$computed['total_due'],
                    ':idp'=>$existing['id']
                ]);
            }
        }
    }

    private function resolvePeriodBounds(\DateTimeInterface $ref, string $freq): array
    {
        $y = (int)$ref->format('Y');
        $m = (int)$ref->format('m');

        if($freq==='monthly'){
            $start = (new DateTimeImmutable("$y-$m-01"))->format('Y-m-d');
            $end   = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
            $due   = (new DateTimeImmutable($end))->modify('+1 month')->modify('last day of this month')->format('Y-m-d');
            $key   = sprintf('%04dM%02d',$y,$m);
        } else {
            $q = intdiv($m-1,3)+1;
            $firstMonth = ($q-1)*3+1;
            $start = (new DateTimeImmutable(sprintf('%04d-%02d-01',$y,$firstMonth)))->format('Y-m-d');
            $end   = (new DateTimeImmutable($start))->modify('+2 months')->modify('last day of this month')->format('Y-m-d');
            $due   = (new DateTimeImmutable($end))->modify('+1 month')->modify('last day of this month')->format('Y-m-d');
            $key   = sprintf('%04dQ%d',$y,$q);
        }
        return ['key'=>$key,'start'=>$start,'end'=>$end,'due'=>$due];
    }

    /**
     * Calcule contributions pour un intervalle
     */
    private function computeContributionsForRange(int $userId, array $micro, string $start, string $end): array
    {
        $microId = (int)$micro['id'];

        // CA période
        $sql = "
          SELECT SUM(CASE WHEN t.amount>=0 AND t.exclude_from_ca=0 THEN t.amount ELSE 0 END) AS ca
          FROM transactions t
          JOIN accounts a ON a.id=t.account_id
          WHERE a.micro_enterprise_id=:m
            AND a.user_id=:u
            AND date(t.date) BETWEEN date(:s) AND date(:e)
        ";
        $st=$this->pdo->prepare($sql);
        $st->execute([':m'=>$microId, ':u'=>$userId, ':s'=>$start, ':e'=>$end]);
        $ca=(float)$st->fetchColumn();

        $activityCode = $micro['activity_code'] ?? null;
        $rateRow = null;
        if($activityCode){
            $st2=$this->pdo->prepare("SELECT * FROM micro_activity_rates WHERE code=:c");
            $st2->execute([':c'=>$activityCode]);
            $rateRow = $st2->fetch(PDO::FETCH_ASSOC);
        }

        $socialRate = $rateRow['social_rate'] ?? null;
        $irRate     = (!empty($micro['ir_liberatoire']) && isset($rateRow['ir_rate'])) ? $rateRow['ir_rate'] : null;
        $cfpRate    = $rateRow['cfp_rate'] ?? null;
        $chamberType = $rateRow['chamber_type'] ?? null;

        // Ajustement ACRE éventuel
        $acre = $micro['acre_reduction_rate'] ?? null;
        if($acre && $socialRate){
            $socialRate = $socialRate * (1 - (float)$acre);
        }

        // Taux chambre simplifié (ex: CCI: usage d’un sous-taux selon vente/service ? -> on reste simple ici)
        $chamberRate = null;
        if($chamberType==='CCI'){
            // approximation : 0.015 pour vente ou 0.044 pour service selon family
            $family = $rateRow['family'] ?? '';
            if($family==='VENTE' || $family==='LOCATION_CLASSEE'){
                $chamberRate = 0.00015; // 0,015 %
            } elseif($family==='SERVICE' || str_starts_with($family,'LIBERAL')) {
                // beaucoup de libéraux n'ont pas forcément la taxe CCI, mais on reste neutre -> 0
                $chamberRate = 0.00044; // 0,044 %
            }
        } elseif($chamberType==='CMA'){
            // simplification : 0.00220 ventes / 0.00480 services (0.220% / 0.480%)
            $family = $rateRow['family'] ?? '';
            if($family==='VENTE') $chamberRate = 0.00220;
            else $chamberRate = 0.00480;
        }

        $socialDue  = $socialRate ? round($ca * $socialRate, 2) : 0.0;
        $irDue      = $irRate     ? round($ca * $irRate, 2)     : 0.0;
        $cfpDue     = $cfpRate    ? round($ca * $cfpRate, 2)    : 0.0;
        $chamberDue = $chamberRate? round($ca * $chamberRate,2) : 0.0;
        $total      = $socialDue + $irDue + $cfpDue + $chamberDue;

        return [
            'ca'=>$ca,
            'social_rate'=>$socialRate,
            'social_due'=>$socialDue,
            'ir_rate'=>$irRate,
            'ir_due'=>$irDue,
            'cfp_rate'=>$cfpRate,
            'cfp_due'=>$cfpDue,
            'chamber_type'=>$chamberType,
            'chamber_rate'=>$chamberRate,
            'chamber_due'=>$chamberDue,
            'total_due'=>$total
        ];
    }

    /* ========== Vérification propriété ========== */
    private function assertMicroOwnership(int $userId, int $microId): void
    {
        $st=$this->pdo->prepare("SELECT 1 FROM micro_enterprises WHERE id=:m AND user_id=:u");
        $st->execute([':m'=>$microId, ':u'=>$userId]);
        if(!$st->fetchColumn()) {
            throw new RuntimeException("Micro-entreprise introuvable.");
        }
    }
}