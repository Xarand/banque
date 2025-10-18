<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use PDO;

class MicroService
{
    public function __construct(private PDO $pdo) {}

    /* Insère les taux par défaut pour une année si manquants */
    public function ensureDefaultRates(int $year): void {
        $defaults = [
            ['vente',    0.1230, 0.0100],
            ['service',  0.2120, 0.0170],
            ['liberale', 0.2110, 0.0220],
        ];
        $chk = $this->pdo->prepare("SELECT 1 FROM micro_rates WHERE year = :y AND activity_type = :t");
        $ins = $this->pdo->prepare("INSERT INTO micro_rates(year,activity_type,social_rate,income_tax_rate)
                                    VALUES(:y,:t,:s,:i)");
        foreach ($defaults as [$type,$soc,$ir]) {
            $chk->execute([':y'=>$year, ':t'=>$type]);
            if (!$chk->fetchColumn()) {
                $ins->execute([':y'=>$year, ':t'=>$type, ':s'=>$soc, ':i'=>$ir]);
            }
        }
    }

    public function createMicro(int $accountId, array $data): int {
        $st = $this->pdo->prepare("INSERT INTO micro_entreprises(account_id,business_name,creation_date,activity_type,income_tax_flat,contribution_period)
            VALUES(:acc,:bn,:cd,:at,:ir,:cp)");
        $st->execute([
            ':acc'=>$accountId,
            ':bn'=>$data['business_name'],
            ':cd'=>$data['creation_date'],
            ':at'=>$data['activity_type'],
            ':ir'=>$data['income_tax_flat'],
            ':cp'=>$data['contribution_period']
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $year = (int)substr($data['creation_date'],0,4);
        $this->ensureDefaultRates($year);
        $this->generateDeadlines($id);
        $this->recomputeAllTurnoverAndEstimates($id);
        return $id;
    }

    public function getMicroByAccount(int $accountId): ?array {
        $st = $this->pdo->prepare("SELECT * FROM micro_entreprises WHERE account_id = :a LIMIT 1");
        $st->execute([':a'=>$accountId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function getDeadlines(int $microId): array {
        $st = $this->pdo->prepare("SELECT * FROM micro_contribution_deadlines WHERE micro_id = :m ORDER BY period_start ASC");
        $st->execute([':m'=>$microId]);
        return $st->fetchAll();
    }

    public function markDeadlinePaid(int $deadlineId): void {
        $st = $this->pdo->prepare("UPDATE micro_contribution_deadlines SET status='paid', updated_at=datetime('now') WHERE id=:id");
        $st->execute([':id'=>$deadlineId]);
    }

    /* Génère les échéances pour l'année de création + année courante si différente */
    public function generateDeadlines(int $microId): void {
        $m = $this->pdo->prepare("SELECT creation_date, contribution_period FROM micro_entreprises WHERE id=:id");
        $m->execute([':id'=>$microId]);
        $row = $m->fetch();
        if (!$row) return;
        $creation = new DateTimeImmutable($row['creation_date']);
        $periodicity = $row['contribution_period'];
        $year = (int)$creation->format('Y');
        $currentYear = (int)(new DateTimeImmutable('today'))->format('Y');

        $this->generateYearDeadlines($microId, $year, $creation, $periodicity);

        if ($currentYear !== $year) {
            $this->generateYearDeadlines($microId, $currentYear, new DateTimeImmutable("$currentYear-01-01"), $periodicity);
        }
    }

    private function generateYearDeadlines(int $microId, int $year, DateTimeImmutable $startFrom, string $periodicity): void {
        $ins = $this->pdo->prepare(
            "INSERT OR IGNORE INTO micro_contribution_deadlines
             (micro_id,period_label,period_start,period_end,due_date,status)
             VALUES(:m,:pl,:ps,:pe,:due,'pending')"
        );

        if ($periodicity === 'mensuelle') {
            for ($month = 1; $month <= 12; $month++) {
                $periodStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year,$month));
                if ($periodStart < $startFrom) continue;
                $periodEnd = $periodStart->modify('last day of this month');
                $label = $periodStart->format('Y-m');
                // URSSAF (simplifié) : dernier jour du mois suivant
                $due = $periodEnd->modify('last day of next month');
                $ins->execute([
                    ':m'=>$microId,
                    ':pl'=>$label,
                    ':ps'=>$periodStart->format('Y-m-d'),
                    ':pe'=>$periodEnd->format('Y-m-d'),
                    ':due'=>$due->format('Y-m-d')
                ]);
            }
        } else {
            $quarters = [
                1 => ['start'=>"$year-01-01",'end'=>"$year-03-31",'due'=>"$year-04-30"],
                2 => ['start'=>"$year-04-01",'end'=>"$year-06-30",'due'=>"$year-07-31"],
                3 => ['start'=>"$year-07-01",'end'=>"$year-09-30",'due'=>"$year-10-31"],
                4 => ['start'=>"$year-10-01",'end'=>"$year-12-31",'due'=>($year+1)."-01-31"],
            ];
            foreach ($quarters as $q=>$data) {
                $periodStart = new DateTimeImmutable($data['start']);
                if ($periodStart < $startFrom) continue;
                $label = $year.'-T'.$q;
                $ins->execute([
                    ':m'=>$microId,
                    ':pl'=>$label,
                    ':ps'=>$data['start'],
                    ':pe'=>$data['end'],
                    ':due'=>$data['due']
                ]);
            }
        }
    }

    /* Recalcule CA & estimations pour toutes les échéances */
    public function recomputeAllTurnoverAndEstimates(int $microId): void {
        $micro = $this->pdo->prepare("SELECT m.*, a.id AS account_id
            FROM micro_entreprises m
            JOIN accounts a ON a.id = m.account_id
            WHERE m.id=:id");
        $micro->execute([':id'=>$microId]);
        $m = $micro->fetch();
        if (!$m) return;

        $rates = $this->loadRatesForYear((int)substr($m['creation_date'],0,4), $m['activity_type']);

        $dl = $this->getDeadlines($microId);
        $upd = $this->pdo->prepare("UPDATE micro_contribution_deadlines
           SET turnover=:t, social_due=:s, income_tax_due=:ir, updated_at=datetime('now') WHERE id=:id");

        foreach ($dl as $d) {
            $turnover = $this->computeTurnover(
                (int)$m['account_id'],
                $d['period_start'],
                $d['period_end']
            );
            $social = $turnover * $rates['social_rate'];
            $incomeTax = $m['income_tax_flat'] ? $turnover * $rates['income_tax_rate'] : null;

            $upd->execute([
                ':t'=>$turnover,
                ':s'=>$social,
                ':ir'=>$incomeTax,
                ':id'=>$d['id']
            ]);
        }
    }

    public function computeTurnover(int $accountId, string $from, string $to): float {
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions
            WHERE account_id=:a
              AND amount>0
              AND include_in_turnover=1
              AND date(date) BETWEEN date(:f) AND date(:t)");
        $st->execute([':a'=>$accountId, ':f'=>$from, ':t'=>$to]);
        return (float)$st->fetchColumn();
    }

    private function loadRatesForYear(int $year, string $activityType): array {
        $this->ensureDefaultRates($year);
        $st = $this->pdo->prepare("SELECT social_rate,income_tax_rate FROM micro_rates
            WHERE year=:y AND activity_type=:t LIMIT 1");
        $st->execute([':y'=>$year, ':t'=>$activityType]);
        $row = $st->fetch();
        if (!$row) {
            return ['social_rate'=>0.0,'income_tax_rate'=>0.0];
        }
        return $row;
    }

    public function getCurrentYearCA(int $accountId, int $year): float {
        $from = sprintf('%04d-01-01',$year);
        $to   = sprintf('%04d-12-31',$year);
        return $this->computeTurnover($accountId, $from, $to);
    }

    public function getAnnualThresholds(string $activityType): array {
        if ($activityType==='vente') {
            return ['ca'=>188700.0, 'tva'=>101000.0];
        }
        return ['ca'=>77700.0, 'tva'=>39100.0];
    }
}