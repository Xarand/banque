<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Version simplifiée :
 * - Ignore transactions.micro_code
 * - Ventile tout le CA >0 (include_in_turnover=1) sur un code unique dérivé de micro_entreprises.activity_type
 * - Mapping activité -> code référentiel micro_activity_codes
 */
class MicroCalculator
{
    public function __construct(private PDO $pdo) {}

    public function enrichDeadlines(int $microId): void
    {
        $mStmt = $this->pdo->prepare("SELECT id, account_id, activity_type, income_tax_flat FROM micro_entreprises WHERE id=:i");
        $mStmt->execute([':i'=>$microId]);
        $micro = $mStmt->fetch();
        if(!$micro) return;

        $incomeTaxFlat = (int)$micro['income_tax_flat'] === 1;

        $dl = $this->pdo->prepare("SELECT * FROM micro_contribution_deadlines WHERE micro_id=:m ORDER BY period_start");
        $dl->execute([':m'=>$microId]);
        $deadlines=$dl->fetchAll();
        if(!$deadlines) return;

        $upd=$this->pdo->prepare("UPDATE micro_contribution_deadlines
            SET breakdown_json=:bj,
                turnover=:tca,
                social_due=:soc,
                income_tax_due=:ir,
                updated_at=datetime('now')
            WHERE id=:id");

        foreach($deadlines as $d){
            $br = $this->computePeriod($micro, $d['period_start'], $d['period_end']);
            $ca = array_sum(array_column($br['codes'],'ca'));
            $upd->execute([
                ':bj'=>json_encode($br, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ':tca'=>$ca,
                ':soc'=>$br['social_total'],
                ':ir'=>$incomeTaxFlat ? $br['ir_total'] : null,
                ':id'=>$d['id']
            ]);
        }
    }

    /**
     * Retourne un breakdown minimal :
     * [
     *   codes => [ code => [code,label,ca,social,ir] ],
     *   social_total, ir_total, cfp_total, taxes_consulaires_total, total_contributions
     * ]
     */
    public function computePeriod(array $microRow, string $periodStart, string $periodEnd): array
    {
        $year=(int)substr($periodStart,0,4);
        $codesRef=$this->loadCodes($year);

        // Mapping activité -> code référentiel
        $map = [
            'vente_logement'  => '508',
            'service_bic'     => '518',
            'liberale_cipav'  => '781_CIPAV',
            'liberale_ssi'    => '781_SSI',
            'meuble_tourisme' => 'MEUBLE_TOURISME'
        ];
        $code = $map[$microRow['activity_type']] ?? '508';

        // CA global sur la période (include_in_turnover=1)
        $q=$this->pdo->prepare("SELECT SUM(amount) FROM transactions
           WHERE account_id=:a AND amount>0 AND include_in_turnover=1
             AND date(date) BETWEEN date(:ps) AND date(:pe)");
        $q->execute([
            ':a'=>(int)$microRow['account_id'],
            ':ps'=>$periodStart,
            ':pe'=>$periodEnd
        ]);
        $ca=(float)($q->fetchColumn() ?: 0);

        $byCode=[];
        $socialTotal=0.0; $irTotal=0.0; $cfpTotal=0.0; $taxCons=0.0;

        if($ca>0 && isset($codesRef[$code])){
            $info=$codesRef[$code];
            $social = $ca * (float)$info['social_rate'];
            $ir     = ((int)$microRow['income_tax_flat']===1) ? $ca * (float)$info['ir_rate'] : 0.0;
            $byCode[$code]=[
                'code'=>$code,
                'label'=>$info['label'],
                'family'=>$info['family'],
                'ca'=>$ca,
                'social'=>$social,
                'ir'=>$ir
            ];
            $socialTotal=$social;
            $irTotal=$ir;
            // CFP
            $cfpRate = (float)$info['cfp_rate'];
            $cfpTotal = $ca * $cfpRate;
            // Taxes consulaires simplifiées (selon consular_type / region_code tu peux étendre)
            $taxCons = $this->computeConsular($microRow, $info, $ca);
        }

        $totalContrib = $socialTotal + $irTotal + $cfpTotal + $taxCons;

        return [
            'period_start'=>$periodStart,
            'period_end'=>$periodEnd,
            'codes'=>$byCode,
            'social_total'=>$socialTotal,
            'ir_total'=>$irTotal,
            'cfp_total'=>$cfpTotal,
            'taxes_consulaires_total'=>$taxCons,
            'total_contributions'=>$totalContrib,
            'meta'=>[
                'income_tax_flat'=>(bool)$microRow['income_tax_flat'],
                'activity_type'=>$microRow['activity_type']
            ]
        ];
    }

    private function loadCodes(int $year): array {
        $st=$this->pdo->prepare("SELECT * FROM micro_activity_codes WHERE year=:y");
        $st->execute([':y'=>$year]);
        $out=[];
        foreach($st->fetchAll() as $r){
            $out[$r['code']]=$r;
        }
        return $out;
    }

    private function computeConsular(array $microRow,array $ref,float $ca): float
    {
        // Simplifié : applique un taux global selon nature (VENTE vs SERVICE) et consular_type
        $type=$microRow['consular_type'] ?? 'NONE';
        if($type==='NONE') return 0.0;
        $region=$microRow['region_code'] ?? 'FR';

        $isVente = in_array($ref['family'],['VENTE','MEUBLE_TOURISME'],true);

        // Sélection des taux selon region
        $venteRate = match($region){
            'AL'=>(float)($ref['cma_rate_vente_al'] ?? 0),
            'MO'=>(float)($ref['cma_rate_vente_mo'] ?? 0),
            default=>(float)($ref['cma_rate_vente_std'] ?? 0)
        };
        $serviceRate = match($region){
            'AL'=>(float)($ref['cma_rate_service_al'] ?? 0),
            'MO'=>(float)($ref['cma_rate_service_mo'] ?? 0),
            default=>(float)($ref['cma_rate_service_std'] ?? 0)
        };
        $cciV = (float)($ref['cci_rate_vente'] ?? 0);
        $cciS = (float)($ref['cci_rate_service'] ?? 0);
        $cciDouble = (float)($ref['cci_rate_double'] ?? 0);

        $tax=0.0;
        if(in_array($type,['CMA','DOUBLE'],true)){
            $tax += $ca * ($isVente ? $venteRate : $serviceRate);
        }
        if(in_array($type,['CCI','DOUBLE'],true)){
            $tax += $ca * ($isVente ? $cciV : $cciS);
            if($type==='DOUBLE') $tax += $ca * $cciDouble;
        }
        return $tax;
    }
}