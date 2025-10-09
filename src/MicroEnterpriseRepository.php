<?php
declare(strict_types=1);

namespace App;

use PDO;
use DateTimeImmutable;
use RuntimeException;

class MicroEnterpriseRepository
{
    private const VERSION_TAG = 'MER-2025-10-09-tdue0';

    private bool $hasTvaCeilingMajor = false;

    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->introspectSchema();
        error_log('MicroEnterpriseRepository version '.self::VERSION_TAG);
    }

    private function introspectSchema(): void
    {
        $cols = $this->pdo->query("PRAGMA table_info(micro_enterprises)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            if ($c['name'] === 'tva_ceiling_major') {
                $this->hasTvaCeilingMajor = true;
            }
        }
    }

    public function getMicro(int $userId, int $id): ?array
    {
        $st = $this->pdo->prepare("
            SELECT * FROM micro_enterprises
            WHERE id=:id AND user_id=:u
            LIMIT 1
        ");
        $st->execute([':id'=>$id, ':u'=>$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function listMicro(int $userId): array
    {
        $st = $this->pdo->prepare("
            SELECT *
            FROM micro_enterprises
            WHERE user_id=:u
            ORDER BY id ASC
        ");
        $st->execute([':u'=>$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function ensureSingleForUser(int $userId): void
    {
        $st = $this->pdo->prepare("SELECT id FROM micro_enterprises WHERE user_id=:u ORDER BY id ASC");
        $st->execute([':u'=>$userId]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) <= 1) return;

        $keep = (int)array_shift($ids);
        $idsList = implode(',', array_map('intval', $ids));

        $this->pdo->exec("UPDATE accounts SET micro_enterprise_id=$keep WHERE micro_enterprise_id IN ($idsList)");
        $this->pdo->exec("DELETE FROM micro_contribution_periods WHERE micro_id IN ($idsList)");
        $this->pdo->exec("DELETE FROM micro_enterprise_categories WHERE micro_id IN ($idsList)");
        $this->pdo->exec("DELETE FROM micro_enterprises WHERE id IN ($idsList)");
    }

    public function getOrCreateSingle(int $userId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM micro_enterprises WHERE user_id=:u LIMIT 1");
        $st->execute([':u'=>$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;

        $this->pdo->prepare("
            INSERT INTO micro_enterprises(user_id,name,created_at)
            VALUES(:u,'Micro',datetime('now'))
        ")->execute([':u'=>$userId]);

        $id = (int)$this->pdo->lastInsertId();
        return $this->getMicro($userId, $id) ?? [];
    }

    public function createMicro(
        int $userId,
        string $name,
        ?float $caCeiling = null,
        ?float $tvaCeiling = null,
        ?string $activityCode = null,
        ?string $frequency = 'quarterly',
        int $irLiberatoire = 0
    ): int {
        $existing = $this->listMicro($userId);
        if ($existing) {
            return (int)$existing[0]['id'];
        }

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException("Nom requis.");
        }

        $defaults = [
            'ca_ceiling'        => $caCeiling,
            'tva_ceiling'       => $tvaCeiling,
            'tva_ceiling_major' => null
        ];

        if ($activityCode) {
            $st = $this->pdo->prepare("SELECT * FROM micro_activity_rates WHERE code=:c LIMIT 1");
            $st->execute([':c'=>$activityCode]);
            if ($bar = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($defaults['ca_ceiling'] === null)  $defaults['ca_ceiling']  = (float)$bar['ca_ceiling'];
                if ($defaults['tva_ceiling'] === null) $defaults['tva_ceiling'] = (float)$bar['tva_ceiling'];
                $defaults['tva_ceiling_major'] = (float)$bar['tva_ceiling_major'];
            }
        }

        $fields = "
            user_id,name,
            ca_ceiling,tva_ceiling,
            activity_code,contributions_frequency,
            ir_liberatoire,created_at
        ";
        $placeholders = "
            :u,:n,
            :ca,:tva,
            :ac,:freq,
            :ir,datetime('now')
        ";
        $params = [
            ':u'=>$userId,
            ':n'=>$name,
            ':ca'=>$defaults['ca_ceiling'],
            ':tva'=>$defaults['tva_ceiling'],
            ':ac'=>$activityCode,
            ':freq'=>$frequency,
            ':ir'=>$irLiberatoire ? 1 : 0
        ];
        if ($this->hasTvaCeilingMajor) {
            $fields       .= ", tva_ceiling_major";
            $placeholders .= ", :tvaM";
            $params[':tvaM'] = $defaults['tva_ceiling_major'];
        }

        $sql = "INSERT INTO micro_enterprises($fields) VALUES ($placeholders)";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        return (int)$this->pdo->lastInsertId();
    }

    public function updateMicro(int $userId, int $id, array $fields): void
    {
        $micro = $this->getMicro($userId, $id);
        if (!$micro) throw new RuntimeException("Micro introuvable.");

        $allowed = [
            'name','regime','ca_ceiling','tva_ceiling','tva_ceiling_major',
            'primary_color','secondary_color','activity_code',
            'contributions_frequency','ir_liberatoire','creation_date',
            'region','acre_reduction_rate'
        ];
        $sets   = [];
        $params = [':id'=>$id, ':u'=>$userId];

        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            if ($k === 'tva_ceiling_major' && !$this->hasTvaCeilingMajor) continue;
            $sets[] = "$k = :$k";
            $params[":$k"] = $v;
        }
        if (!$sets) return;

        $sets[] = "updated_at = datetime('now')";
        $sql = "UPDATE micro_enterprises SET ".implode(', ', $sets)." WHERE id=:id AND user_id=:u";
        $st  = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    public function generateOrRefreshCurrentPeriod(int $userId, int $microId, DateTimeImmutable $today): void
    {
        $micro = $this->getMicro($userId, $microId);
        if (!$micro) throw new RuntimeException("Micro introuvable.");

        $freq  = $micro['contributions_frequency'] ?: 'quarterly';
        $year  = (int)$today->format('Y');
        $month = (int)$today->format('m');

        if ($freq === 'monthly') {
            $periodStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $periodKey   = $periodStart->format('Y-m');
            $periodEnd   = $periodStart->modify('last day of this month');
            $dueDate     = $periodEnd->modify('+1 month')->modify('last day of this month');
        } else {
            $q          = intdiv($month - 1, 3) + 1;
            $firstMonth = ($q - 1) * 3 + 1;
            $periodStart= new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $firstMonth));
            $periodKey  = sprintf('%04dQ%d', $year, $q);
            $periodEnd  = $periodStart->modify('+2 months')->modify('last day of this month');
            $dueDate    = $periodEnd->modify('+30 days');
        }

        // Chargement barème
        $activity = null;
        if (!empty($micro['activity_code'])) {
            $stRate = $this->pdo->prepare("SELECT * FROM micro_activity_rates WHERE code=:c LIMIT 1");
            $stRate->execute([':c'=>$micro['activity_code']]);
            $activity = $stRate->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // CA de la période: uniquement les crédits (>0)
        $ca = $this->periodCA(
            $userId,
            $microId,
            $periodStart->format('Y-m-d'),
            $periodEnd->format('Y-m-d')
        );

        $socialRate  = $activity['social_rate'] ?? null;
        $irRate      = ($micro['ir_liberatoire'] ?? 0) ? ($activity['ir_rate'] ?? null) : null;
        $cfpRate     = $activity['cfp_rate'] ?? null;
        $chamberRate = (!empty($activity['chamber_type']) && $activity['chamber_rate_default'] !== null)
            ? (float)$activity['chamber_rate_default'] : null;

        $acre = $micro['acre_reduction_rate'] ?? null;
        if ($acre !== null && $acre > 0 && $acre < 1) {
            $socialRate  = $socialRate  !== null ? $socialRate  * (1 - $acre) : null;
            $chamberRate = $chamberRate !== null ? $chamberRate * (1 - $acre) : null;
        }

        $socialDue  = $socialRate  !== null ? round($ca * $socialRate, 2) : null;
        $irDue      = $irRate      !== null ? round($ca * $irRate,     2) : null;
        $cfpDue     = $cfpRate     !== null ? round($ca * $cfpRate,    2) : null;
        $chamberDue = $chamberRate !== null ? round($ca * $chamberRate,2) : null;

        // Nouveau calcul: total_due = 0.00 si au moins un taux s'applique, même si CA=0
        $hasAnyRate = ($socialRate !== null) || ($irRate !== null) || ($cfpRate !== null) || ($chamberRate !== null);
        $sumDue = ($socialDue ?? 0) + ($irDue ?? 0) + ($cfpDue ?? 0) + ($chamberDue ?? 0);
        $total = $hasAnyRate ? round($sumDue, 2) : null;

        $st = $this->pdo->prepare("
            SELECT id,status FROM micro_contribution_periods
            WHERE micro_id=:m AND period_key=:k
            LIMIT 1
        ");
        $st->execute([':m'=>$microId, ':k'=>$periodKey]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $paid = $existing['status'] === 'paid';

            $sql = "
                UPDATE micro_contribution_periods
                   SET updated_at = datetime('now'),
                       ca_amount  = :ca
                       ".($paid ? "" : ",
                       social_rate_used   = :social_rate_used,
                       social_due         = :social_due,
                       ir_rate_used       = :ir_rate_used,
                       ir_due             = :ir_due,
                       cfp_rate_used      = :cfp_rate_used,
                       cfp_due            = :cfp_due,
                       chamber_type       = :chamber_type,
                       chamber_rate_used  = :chamber_rate_used,
                       chamber_due        = :chamber_due,
                       total_due          = :total_due")."
                 WHERE id = :period_id
            ";

            $up = $this->pdo->prepare($sql);
            $params = [
                ':ca'        => $ca,
                ':period_id' => $existing['id']
            ];
            if (!$paid) {
                $params += [
                    ':social_rate_used'  => $socialRate,
                    ':social_due'        => $socialDue ?? 0.0,
                    ':ir_rate_used'      => $irRate,
                    ':ir_due'            => $irDue ?? 0.0,
                    ':cfp_rate_used'     => $cfpRate,
                    ':cfp_due'           => $cfpDue ?? 0.0,
                    ':chamber_type'      => $activity['chamber_type'] ?? null,
                    ':chamber_rate_used' => $chamberRate,
                    ':chamber_due'       => $chamberDue ?? 0.0,
                    ':total_due'         => $total
                ];
            }
            $up->execute($params);

        } else {
            $ins = $this->pdo->prepare("
                INSERT INTO micro_contribution_periods(
                  micro_id,period_key,period_start,period_end,due_date,
                  ca_amount,
                  social_rate_used,social_due,
                  ir_rate_used,ir_due,
                  cfp_rate_used,cfp_due,
                  chamber_type,chamber_rate_used,chamber_due,
                  total_due,status,created_at
                ) VALUES (
                  :m,:k,:ps,:pe,:dd,
                  :ca,
                  :social_rate_used,:social_due,
                  :ir_rate_used,:ir_due,
                  :cfp_rate_used,:cfp_due,
                  :chamber_type,:chamber_rate_used,:chamber_due,
                  :total_due,'pending',datetime('now')
                )
            ");
            $ins->execute([
                ':m'=>$microId,
                ':k'=>$periodKey,
                ':ps'=>$periodStart->format('Y-m-d'),
                ':pe'=>$periodEnd->format('Y-m-d'),
                ':dd'=>$dueDate->format('Y-m-d'),
                ':ca'=>$ca,
                ':social_rate_used'=>$socialRate,
                ':social_due'=>$socialDue ?? 0.0,
                ':ir_rate_used'=>$irRate,
                ':ir_due'=>$irDue ?? 0.0,
                ':cfp_rate_used'=>$cfpRate,
                ':cfp_due'=>$cfpDue ?? 0.0,
                ':chamber_type'=>$activity['chamber_type'] ?? null,
                ':chamber_rate_used'=>$chamberRate,
                ':chamber_due'=>$chamberDue ?? 0.0,
                ':total_due'=>$total
            ]);
        }
    }

    public function listContributionPeriods(int $userId, int $microId): array
    {
        $micro = $this->getMicro($userId, $microId);
        if (!$micro) return [];
        $st = $this->pdo->prepare("
            SELECT *
            FROM micro_contribution_periods
            WHERE micro_id=:m
            ORDER BY period_start DESC
        ");
        $st->execute([':m'=>$microId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markPeriodPaid(int $userId, int $periodId): void
    {
        $st = $this->pdo->prepare("
            SELECT p.id, p.micro_id, m.user_id
            FROM micro_contribution_periods p
            JOIN micro_enterprises m ON m.id = p.micro_id
            WHERE p.id = :id
            LIMIT 1
        ");
        $st->execute([':id'=>$periodId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['user_id'] !== $userId) {
            throw new RuntimeException("Période introuvable.");
        }
        $up = $this->pdo->prepare("
            UPDATE micro_contribution_periods
               SET status='paid',
                   paid_at=datetime('now'),
                   updated_at=datetime('now')
             WHERE id=:id
        ");
        $up->execute([':id'=>$periodId]);
    }

    // CA de période: uniquement les crédits (>0), et non les débits
    private function periodCA(int $userId, int $microId, string $start, string $end): float
    {
        $sql = "
            SELECT COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0)
            FROM transactions t
            JOIN accounts a ON a.id = t.account_id
            WHERE t.user_id = :u
              AND a.micro_enterprise_id = :m
              AND t.exclude_from_ca = 0
              AND date(t.date) >= date(:s)
              AND date(t.date) <= date(:e)
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':u'=>$userId,
            ':m'=>$microId,
            ':s'=>$start,
            ':e'=>$end
        ]);
        return (float)$st->fetchColumn();
    }

    public function syncCeilingsFromRates(): int
    {
        $rates = $this->pdo->query("SELECT code, ca_ceiling, tva_ceiling, tva_ceiling_major FROM micro_activity_rates")
            ->fetchAll(PDO::FETCH_ASSOC);
        if (!$rates) return 0;

        $sql = "
          UPDATE micro_enterprises
             SET ca_ceiling = :ca,
                 tva_ceiling = :tva
                 ".($this->hasTvaCeilingMajor ? ", tva_ceiling_major = :tvaM" : "")."
           WHERE activity_code = :code
        ";
        $st = $this->pdo->prepare($sql);
        $updated = 0;

        foreach ($rates as $r) {
            $params = [
                ':ca'=>$r['ca_ceiling'],
                ':tva'=>$r['tva_ceiling'],
                ':code'=>$r['code']
            ];
            if ($this->hasTvaCeilingMajor) {
                $params[':tvaM'] = $r['tva_ceiling_major'];
            }
            $st->execute($params);
            $updated += $st->rowCount();
        }
        return $updated;
    }

    public function recalculateAllOpenPeriods(int $userId, ?DateTimeImmutable $today = null): void
    {
        $today ??= new DateTimeImmutable();
        $micros = $this->listMicro($userId);
        foreach ($micros as $m) {
            $this->generateOrRefreshCurrentPeriod($userId, (int)$m['id'], $today);
        }
    }
}