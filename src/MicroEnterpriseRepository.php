<?php
declare(strict_types=1);

namespace App;

use PDO;
use DateTimeImmutable;
use RuntimeException;

/**
 * Gestion de la micro‑entreprise (une par utilisateur dans ton modèle cible).
 *
 * Hypothèses de schéma :
 *  - Table micro_enterprises :
 *      id, user_id, name, regime, ca_ceiling, tva_ceiling,
 *      primary_color, secondary_color, created_at, updated_at,
 *      activity_code, contributions_frequency, ir_liberatoire,
 *      creation_date, region, acre_reduction_rate
 *  - Table micro_contribution_periods :
 *      id, micro_id, period_key, period_start, period_end, due_date,
 *      ca_amount, social_rate_used, social_due,
 *      ir_rate_used, ir_due, cfp_rate_used, cfp_due,
 *      chamber_type, chamber_rate_used, chamber_due,
 *      total_due, status, paid_at, notes, created_at, updated_at
 *  - Table micro_activity_rates :
 *      code (unique), social_rate, ir_rate, cfp_rate,
 *      chamber_type, ca_ceiling, tva_ceiling, tva_alert_threshold
 *  - Table accounts : micro_enterprise_id nullable
 *  - Table transactions :
 *      user_id, account_id, amount, date (YYYY-MM-DD), exclude_from_ca (0|1)
 *
 * Les transactions prises dans le CA :
 *  - amount > 0 ou < 0 (on additionne tel quel)
 *  - exclude_from_ca = 0
 *  - rattachement indirect : transaction.account_id -> accounts.micro_enterprise_id
 *
 * Méthodes principales :
 *  - getMicro / listMicro
 *  - createMicro / updateMicro
 *  - getOrCreateSingle / ensureSingleForUser (unicité)
 *  - generateOrRefreshCurrentPeriod (calcule / met à jour la période courante)
 *  - computeYearToDate (agrégat annuel)
 *  - listContributionPeriods
 *  - markPeriodPaid
 */
class MicroEnterpriseRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /* =========================================================
       Sélection de base
       ========================================================= */
    public function getMicro(int $userId, int $id): ?array
    {
        $st = $this->pdo->prepare("
            SELECT *
            FROM micro_enterprises
            WHERE id=:id AND user_id=:u
            LIMIT 1
        ");
        $st->execute([':id'=>$id, ':u'=>$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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

    /* =========================================================
       Unicité : helpers
       ========================================================= */

    /**
     * Garantit qu'il ne reste qu'une micro pour un utilisateur.
     * Si plusieurs existent (situation héritée), on garde la plus ancienne (id min),
     * on rattache les comptes, on supprime les périodes & catégories des doublons.
     */
    public function ensureSingleForUser(int $userId): void
    {
        $st = $this->pdo->prepare("
            SELECT id
            FROM micro_enterprises
            WHERE user_id=:u
            ORDER BY id ASC
        ");
        $st->execute([':u'=>$userId]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);

        if (count($ids) <= 1) {
            return;
        }

        $keep = (int)array_shift($ids);
        if (!$ids) {
            return;
        }

        $idsList = implode(',', array_map('intval',$ids));

        // Rattacher comptes
        $this->pdo->exec("
            UPDATE accounts
               SET micro_enterprise_id = $keep
             WHERE micro_enterprise_id IN ($idsList)
        ");

        // Supprimer périodes & catégories
        $this->pdo->exec("DELETE FROM micro_contribution_periods WHERE micro_id IN ($idsList)");
        $this->pdo->exec("DELETE FROM micro_enterprise_categories WHERE micro_id IN ($idsList)");

        // Supprimer micro en trop
        $this->pdo->exec("DELETE FROM micro_enterprises WHERE id IN ($idsList)");
    }

    /**
     * Retourne la micro unique ou la crée si elle n'existe pas.
     */
    public function getOrCreateSingle(int $userId): array
    {
        $st = $this->pdo->prepare("
            SELECT *
            FROM micro_enterprises
            WHERE user_id=:u
            LIMIT 1
        ");
        $st->execute([':u'=>$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $this->pdo->prepare("
            INSERT INTO micro_enterprises(user_id,name,created_at)
            VALUES(:u,'Micro',datetime('now'))
        ")->execute([':u'=>$userId]);
        $id = (int)$this->pdo->lastInsertId();
        return $this->getMicro($userId,$id) ?? [];
    }

    /* =========================================================
       CRUD
       ========================================================= */
    public function createMicro(
        int $userId,
        string $name,
        ?float $caCeiling = null,
        ?float $tvaCeiling = null,
        ?string $activityCode = null,
        ?string $frequency = 'quarterly',
        int $irLiberatoire = 0
    ): int {
        // Empêche création si déjà existante (unicité logique)
        $existing = $this->listMicro($userId);
        if ($existing) {
            return (int)$existing[0]['id'];
        }

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException("Nom requis.");
        }

        $st = $this->pdo->prepare("
            INSERT INTO micro_enterprises
                (user_id,name,ca_ceiling,tva_ceiling,activity_code,contributions_frequency,ir_liberatoire,created_at)
            VALUES
                (:u,:n,:ca,:tva,:ac,:freq,:ir,datetime('now'))
        ");
        $st->execute([
            ':u'=>$userId,
            ':n'=>$name,
            ':ca'=>$caCeiling,
            ':tva'=>$tvaCeiling,
            ':ac'=>$activityCode,
            ':freq'=>$frequency,
            ':ir'=>$irLiberatoire ? 1 : 0
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateMicro(int $userId, int $id, array $fields): void
    {
        $micro = $this->getMicro($userId,$id);
        if (!$micro) {
            throw new RuntimeException("Micro introuvable.");
        }

        $allowed = [
            'name','regime','ca_ceiling','tva_ceiling',
            'primary_color','secondary_color','activity_code',
            'contributions_frequency','ir_liberatoire','creation_date',
            'region','acre_reduction_rate'
        ];
        $sets = [];
        $params = [':id'=>$id, ':u'=>$userId];
        foreach ($fields as $k=>$v) {
            if (!in_array($k,$allowed,true)) {
                continue;
            }
            $sets[] = "$k = :$k";
            $params[":$k"] = $v;
        }
        if (!$sets) {
            return;
        }
        $sets[] = "updated_at = datetime('now')";
        $sql = "
            UPDATE micro_enterprises
               SET ".implode(', ',$sets)."
             WHERE id=:id AND user_id=:u
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    /* =========================================================
       Périodes & calculs
       ========================================================= */

    /**
     * Calcule (ou rafraîchit) la période courante :
     *  - Mensuelle ou trimestrielle selon contributions_frequency
     *  - period_key :
     *      monthly   => YYYY-MM
     *      quarterly => YYYYQn (ex: 2025Q1)
     *  - due_date :
     *      monthly   => fin du mois suivant, ex: mois = 2025-10 => due = 2025-11-30
     *      quarterly => 30 jours après la fin du trimestre (adaptable)
     */
    public function generateOrRefreshCurrentPeriod(int $userId, int $microId, DateTimeImmutable $today): void
    {
        $micro = $this->getMicro($userId,$microId);
        if (!$micro) {
            throw new RuntimeException("Micro introuvable.");
        }

        $freq = $micro['contributions_frequency'] ?: 'quarterly';

        $year = (int)$today->format('Y');
        $month = (int)$today->format('m');

        if ($freq === 'monthly') {
            $periodStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $periodKey   = $periodStart->format('Y-m');
            $periodEnd   = $periodStart->modify('last day of this month');
            $dueDate     = $periodEnd->modify('+1 month')->modify('last day of this month');
        } else {
            // Trimestriel
            $q = intdiv($month - 1, 3) + 1;
            $firstMonth = ($q - 1) * 3 + 1;
            $periodStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $firstMonth));
            $periodKey   = sprintf('%04dQ%d', $year, $q);
            $periodEnd   = $periodStart->modify('+2 months')->modify('last day of this month');
            // Échéance : 30 jours après la fin du trimestre (simplifié)
            $dueDate = $periodEnd->modify('+30 days');
        }

        // Récupère barème activité pour les taux
        $activity = null;
        if (!empty($micro['activity_code'])) {
            $stRate = $this->pdo->prepare("SELECT * FROM micro_activity_rates WHERE code=:c LIMIT 1");
            $stRate->execute([':c'=>$micro['activity_code']]);
            $activity = $stRate->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Calcul CA de la période (transactions rattachées via account.micro_enterprise_id)
        $ca = $this->periodCA($userId, $microId, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d'));

        // Taux d'origine
        $socialRate   = $activity['social_rate'] ?? null;
        $irRate       = ($micro['ir_liberatoire'] ?? 0) ? ($activity['ir_rate'] ?? null) : null;
        $cfpRate      = $activity['cfp_rate'] ?? null;
        $chamberType  = $activity['chamber_type'] ?? null;
        $chamberRate  = $this->resolveChamberRate($chamberType, $activity, $micro);

        // Application éventuelle réduction ACRE
        $acreReduction = $micro['acre_reduction_rate'] ?? null;
        if ($acreReduction !== null && $acreReduction > 0 && $acreReduction < 1) {
            if ($socialRate !== null) {
                $socialRate = $socialRate * (1 - $acreReduction);
            }
            if ($irRate !== null) {
                $irRate = $irRate * (1 - $acreReduction);
            }
            if ($cfpRate !== null) {
                $cfpRate = $cfpRate * (1 - $acreReduction);
            }
            if ($chamberRate !== null) {
                $chamberRate = $chamberRate * (1 - $acreReduction);
            }
        }

        // Montants
        $socialDue  = ($socialRate !== null) ? round($ca * $socialRate, 2) : null;
        $irDue      = ($irRate !== null) ? round($ca * $irRate, 2) : null;
        $cfpDue     = ($cfpRate !== null) ? round($ca * $cfpRate, 2) : null;
        $chamberDue = ($chamberRate !== null) ? round($ca * $chamberRate, 2) : null;

        $total = 0.0;
        foreach ([$socialDue, $irDue, $cfpDue, $chamberDue] as $v) {
            if ($v !== null) {
                $total += $v;
            }
        }
        $total = $total > 0 ? round($total, 2) : null;

        // Upsert de la période
        $st = $this->pdo->prepare("
            SELECT id, status
            FROM micro_contribution_periods
            WHERE micro_id=:m AND period_key=:k
            LIMIT 1
        ");
        $st->execute([':m'=>$microId, ':k'=>$periodKey]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Si payée, on ne modifie pas les montants (sauf si tu veux recalculer même payée)
            $paid = $existing['status'] === 'paid';
            $sqlU = "
                UPDATE micro_contribution_periods
                   SET updated_at = datetime('now'),
                       ca_amount = :ca
                       ".($paid ? "" : ",
                       social_rate_used=:sr,
                       social_due=:sd,
                       ir_rate_used=:ir,
                       ir_due=:id,
                       cfp_rate_used=:cr,
                       cfp_due=:cd,
                       chamber_type=:cht,
                       chamber_rate_used=:chr,
                       chamber_due=:chd,
                       total_due=:td")."
                 WHERE id=:id
            ";
            $up = $this->pdo->prepare($sqlU);
            $params = [
                ':ca'=>$ca,
                ':id'=>$existing['id'],
            ];
            if (!$paid) {
                $params += [
                    ':sr'=>$socialRate,
                    ':sd'=>$socialDue,
                    ':ir'=>$irRate,
                    ':id'=>$irDue,
                    ':cr'=>$cfpRate,
                    ':cd'=>$cfpDue,
                    ':cht'=>$chamberType,
                    ':chr'=>$chamberRate,
                    ':chd'=>$chamberDue,
                    ':td'=>$total
                ];
            }
            $up->execute($params);
        } else {
            $ins = $this->pdo->prepare("
                INSERT INTO micro_contribution_periods(
                    micro_id, period_key, period_start, period_end, due_date,
                    ca_amount,
                    social_rate_used, social_due,
                    ir_rate_used, ir_due,
                    cfp_rate_used, cfp_due,
                    chamber_type, chamber_rate_used, chamber_due,
                    total_due, status, created_at
                ) VALUES (
                    :m,:k,:ps,:pe,:dd,
                    :ca,
                    :sr,:sd,
                    :ir,:id,
                    :cr,:cd,
                    :cht,:chr,:chd,
                    :td,'pending',datetime('now')
                )
            ");
            $ins->execute([
                ':m'=>$microId,
                ':k'=>$periodKey,
                ':ps'=>$periodStart->format('Y-m-d'),
                ':pe'=>$periodEnd->format('Y-m-d'),
                ':dd'=>$dueDate->format('Y-m-d'),
                ':ca'=>$ca,
                ':sr'=>$socialRate,
                ':sd'=>$socialDue,
                ':ir'=>$irRate,
                ':id'=>$irDue,
                ':cr'=>$cfpRate,
                ':cd'=>$cfpDue,
                ':cht'=>$chamberType,
                ':chr'=>$chamberRate,
                ':chd'=>$chamberDue,
                ':td'=>$total
            ]);
        }
    }

    /**
     * Calcule des agrégats YTD (année civile).
     * Retourne :
     *  - ca      : somme des montants >0 - (débité via amount <0 ? => on additionne tel quel)
     *  - debits  : somme négative (convertie en positif) pour afficher séparation
     *  - net     : somme brute (positifs + négatifs)
     */
    public function computeYearToDate(int $userId, int $microId, int $year): array
    {
        $start = sprintf('%04d-01-01', $year);
        $end   = sprintf('%04d-12-31', $year);

        $sql = "
          SELECT
            COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount END),0) AS positive,
            COALESCE(SUM(CASE WHEN t.amount < 0 THEN t.amount END),0) AS negative,
            COALESCE(SUM(t.amount),0) AS total
          FROM transactions t
          JOIN accounts a ON a.id = t.account_id
          WHERE t.user_id=:u
            AND a.micro_enterprise_id=:m
            AND t.exclude_from_ca=0
            AND date(t.date)>=date(:s)
            AND date(t.date)<=date(:e)
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':u'=>$userId,
            ':m'=>$microId,
            ':s'=>$start,
            ':e'=>$end
        ]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [
            'positive'=>0,
            'negative'=>0,
            'total'=>0
        ];

        // CA ici = somme brute transactionnelle (positifs + négatifs)
        // ou on pourrait décider CA = positive + negative (si negative <0) => total = net
        $ca = (float)$r['positive'] + (float)$r['negative']; // car negative est négatif
        $debits = - (float)$r['negative']; // affichage toujours positif

        return [
            'ca'     => $ca,
            'debits' => $debits,
            'net'    => (float)$r['total']
        ];
    }

    /**
     * Liste des périodes de contributions d'une micro (ordre descendant par date clé).
     */
    public function listContributionPeriods(int $userId, int $microId): array
    {
        // Sécurité : vérifier la micro appartient bien à l'user
        $m = $this->getMicro($userId,$microId);
        if (!$m) {
            return [];
        }
        $st = $this->pdo->prepare("
            SELECT *
            FROM micro_contribution_periods
            WHERE micro_id=:m
            ORDER BY period_start DESC, id DESC
        ");
        $st->execute([':m'=>$microId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Marque une période comme payée (status = paid, paid_at = now).
     */
    public function markPeriodPaid(int $userId, int $periodId): void
    {
        // Vérifie appartenance via jointure micro
        $st = $this->pdo->prepare("
          SELECT p.id,p.micro_id,m.user_id
          FROM micro_contribution_periods p
          JOIN micro_enterprises m ON m.id=p.micro_id
          WHERE p.id=:id
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

    /* =========================================================
       Sous-calculs internes
       ========================================================= */

    /**
     * CA d'une plage (inclut crédits et débits, en additionnant amount brut,
     * en excluant les transactions exclude_from_ca=1).
     */
    private function periodCA(int $userId, int $microId, string $start, string $end): float
    {
        $sql = "
          SELECT COALESCE(SUM(t.amount),0) AS ca
          FROM transactions t
          JOIN accounts a ON a.id = t.account_id
          WHERE t.user_id=:u
            AND a.micro_enterprise_id=:m
            AND t.exclude_from_ca=0
            AND date(t.date)>=date(:s)
            AND date(t.date)<=date(:e)
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

    /**
     * Détermine le taux de chambre (CCI / CMA) si applicable.
     * Pour simplifier : soit on lit un champ chamber_type dans l'activité,
     * soit on le laisse tel quel (retourne taux codé si extension future).
     *
     * Actuellement : si chamber_type existe dans l'activité, on ne stocke pas
     * explicitement un taux dédié (il pourrait être fusionné dans social_rate
     * dans certaines activités). Ici on peut retourner null par défaut.
     *
     * Si tu veux un taux distinct, ajoute un champ chamber_rate dans micro_activity_rates.
     */
    private function resolveChamberRate(?string $chamberType, ?array $activity, array $micro): ?float
    {
        if (!$chamberType) {
            return null;
        }
        // Ex : tu pourrais avoir un mapping :
        // CCI => 0.0010 (0.10%), CMA => 0.0040 etc. (Adaptation selon logique réelle)
        // Pour l’instant on retourne null si pas de champ 'chamber_rate_used' dans activity.
        if (isset($activity['chamber_rate']) && $activity['chamber_rate'] !== null) {
            return (float)$activity['chamber_rate'];
        }
        return null;
    }
}