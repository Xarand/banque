<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

class FinanceRepository
{
    private PDO $pdo;

    public function __construct(Database|PDO $db)
    {
        $this->pdo = $db instanceof Database ? $db->pdo() : $db;
    }

    /* =========================
       Comptes
       ========================= */
    public function createAccount(
        int $userId,
        string $name,
        string $currency = 'EUR',
        ?string $type = null,
        float $initial = 0.0
    ): int {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException("Nom de compte requis.");
        }
        $check = $this->pdo->prepare("SELECT 1 FROM accounts WHERE user_id=:u AND lower(name)=lower(:n)");
        $check->execute([':u' => $userId, ':n' => $name]);
        if ($check->fetchColumn()) {
            throw new RuntimeException("Ce nom de compte existe déjà.");
        }
        $stmt = $this->pdo->prepare("
          INSERT INTO accounts(user_id,name,type,currency,initial_balance)
          VALUES(:u,:n,:t,:c,:b)
        ");
        $stmt->execute([
            ':u' => $userId,
            ':n' => $name,
            ':t' => $type,
            ':c' => $currency,
            ':b' => $initial
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function listAccounts(int $userId): array
    {
        $sql = "
          SELECT a.*,
                 (a.initial_balance + COALESCE(
                   (SELECT SUM(amount)
                      FROM transactions t
                      WHERE t.account_id=a.id
                        AND t.user_id=:u),0)
                 ) AS current_balance
          FROM accounts a
          WHERE a.user_id=:u
          ORDER BY a.name COLLATE NOCASE ASC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':u' => $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /* =========================
       Catégories
       ========================= */
    public function createCategory(int $userId, string $name, ?string $type = null): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException("Nom de catégorie requis.");
        }
        if ($type !== null && !in_array($type, ['income', 'expense'], true)) {
            $type = null;
        }
        $check = $this->pdo->prepare("SELECT 1 FROM categories WHERE user_id=:u AND lower(name)=lower(:n)");
        $check->execute([':u' => $userId, ':n' => $name]);
        if ($check->fetchColumn()) {
            throw new RuntimeException("Catégorie déjà existante.");
        }
        $st = $this->pdo->prepare("
          INSERT INTO categories(user_id,name,type)
          VALUES(:u,:n,:t)
        ");
        $st->execute([':u' => $userId, ':n' => $name, ':t' => $type]);
        return (int)$this->pdo->lastInsertId();
    }

    public function listCategories(int $userId): array
    {
        $st = $this->pdo->prepare("
          SELECT id,name,type
          FROM categories
          WHERE user_id=:u
          ORDER BY name COLLATE NOCASE
        ");
        $st->execute([':u' => $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /* =========================
       Transactions
       ========================= */

    /**
     * direction: 'credit'|'debit'|null (si pas de catégorie typée)
     */
    public function addTransaction(
        int $userId,
        int $accountId,
        string $date,
        float $amount,
        string $desc = '',
        ?int $categoryId = null,
        ?string $notes = null,
        ?string $direction = null,
        int $excludeFromCa = 0
    ): int {
        // Vérif compte
        $acc = $this->pdo->prepare("SELECT 1 FROM accounts WHERE id=:a AND user_id=:u");
        $acc->execute([':a' => $accountId, ':u' => $userId]);
        if (!$acc->fetchColumn()) {
            throw new RuntimeException("Compte introuvable.");
        }

        // Catégorie
        $catType = null;
        if ($categoryId !== null) {
            $cat = $this->pdo->prepare("SELECT type FROM categories WHERE id=:c AND user_id=:u");
            $cat->execute([':c' => $categoryId, ':u' => $userId]);
            $catType = $cat->fetchColumn();
            if ($catType === false) {
                throw new RuntimeException("Catégorie invalide.");
            }
            if (!in_array($catType, ['income', 'expense', null], true)) {
                $catType = null;
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException("Date invalide.");
        }
        if ($amount == 0.0) {
            throw new RuntimeException("Montant nul interdit.");
        }

        $normalized = abs($amount);
        if ($catType === 'expense') {
            $normalized = -$normalized;
        } elseif ($catType === 'income') {
            // reste positif
        } else {
            if ($direction === 'debit') {
                $normalized = -$normalized;
            }
        }

        $st = $this->pdo->prepare("
          INSERT INTO transactions(user_id,account_id,date,description,amount,category_id,notes,exclude_from_ca)
          VALUES(:u,:a,:d,:ds,:amt,:cat,:notes,:exca)
        ");
        $st->execute([
            ':u'    => $userId,
            ':a'    => $accountId,
            ':d'    => $date,
            ':ds'   => $desc ?: null,
            ':amt'  => $normalized,
            ':cat'  => $categoryId,
            ':notes'=> $notes ?: null,
            ':exca' => $excludeFromCa ? 1 : 0,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getTransactionById(int $userId, int $id): ?array
    {
        $st = $this->pdo->prepare("
          SELECT t.*, c.type AS category_type
          FROM transactions t
          LEFT JOIN categories c ON c.id=t.category_id
          WHERE t.id=:id AND t.user_id=:u
          LIMIT 1
        ");
        $st->execute([':id' => $id, ':u' => $userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * direction: 'credit'|'debit'|null
     */
    public function updateTransaction(
        int $userId,
        int $id,
        int $accountId,
        string $date,
        float $amount,
        string $desc = '',
        ?int $categoryId = null,
        ?string $notes = null,
        ?string $direction = null,
        int $excludeFromCa = 0
    ): void {
        $existing = $this->getTransactionById($userId, $id);
        if (!$existing) {
            throw new RuntimeException("Transaction introuvable.");
        }

        $acc = $this->pdo->prepare("SELECT 1 FROM accounts WHERE id=:a AND user_id=:u");
        $acc->execute([':a' => $accountId, ':u' => $userId]);
        if (!$acc->fetchColumn()) {
            throw new RuntimeException("Compte invalide.");
        }

        $catType = null;
        if ($categoryId !== null) {
            $cat = $this->pdo->prepare("SELECT type FROM categories WHERE id=:c AND user_id=:u");
            $cat->execute([':c' => $categoryId, ':u' => $userId]);
            $catType = $cat->fetchColumn();
            if ($catType === false) {
                throw new RuntimeException("Catégorie invalide.");
            }
            if (!in_array($catType, ['income', 'expense', null], true)) {
                $catType = null;
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException("Date invalide.");
        }
        if ($amount == 0.0) {
            throw new RuntimeException("Montant nul interdit.");
        }

        $normalized = abs($amount);
        if ($catType === 'expense') {
            $normalized = -$normalized;
        } elseif ($catType === 'income') {
            // reste positif
        } else {
            if ($direction === 'debit') {
                $normalized = -$normalized;
            }
        }

        $st = $this->pdo->prepare("
          UPDATE transactions
             SET account_id=:a,
                 date=:d,
                 description=:ds,
                 amount=:amt,
                 category_id=:cat,
                 notes=:notes,
                 exclude_from_ca=:exca
           WHERE id=:id AND user_id=:u
        ");
        $st->execute([
            ':a'    => $accountId,
            ':d'    => $date,
            ':ds'   => $desc ?: null,
            ':amt'  => $normalized,
            ':cat'  => $categoryId,
            ':notes'=> $notes ?: null,
            ':exca' => $excludeFromCa ? 1 : 0,
            ':id'   => $id,
            ':u'    => $userId
        ]);
        if ($st->rowCount() === 0) {
            // Pas forcément une erreur si aucune donnée n'a changé
        }
    }

    public function deleteTransaction(int $userId, int $id): void
    {
        $st = $this->pdo->prepare("DELETE FROM transactions WHERE id=:id AND user_id=:u");
        $st->execute([':id' => $id, ':u' => $userId]);
    }

    public function searchTransactions(int $userId, array $filters, int $limit = 100): array
    {
        $where  = ["t.user_id = :u"];
        $params = [':u' => $userId];

        if (!empty($filters['account_id'])) {
            $where[] = "t.account_id = :acc";
            $params[':acc'] = (int)$filters['account_id'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = "t.category_id = :cat";
            $params[':cat'] = (int)$filters['category_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "date(t.date) >= date(:df)";
            $params[':df'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "date(t.date) <= date(:dt)";
            $params[':dt'] = $filters['date_to'];
        }

        $sql = "
          SELECT t.id,t.date,t.description,t.amount,t.notes,t.exclude_from_ca,
                 a.name AS account, c.name AS category, c.type AS category_type
          FROM transactions t
          JOIN accounts a ON a.id=t.account_id AND a.user_id=t.user_id
          LEFT JOIN categories c ON c.id=t.category_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY date(t.date) DESC, t.id DESC
          LIMIT " . (int)$limit;

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float)$r['amount'];
        }

        return [
            'rows'  => $rows,
            'count' => count($rows),
            'sum'   => $total
        ];
    }

    /**
     * Export toutes transactions filtrées, sans limite
     */
    public function exportTransactions(int $userId, array $filters): \PDOStatement
    {
        $where   = ["t.user_id = :u"];
        $params  = [':u' => $userId];

        if (!empty($filters['account_id'])) {
            $where[]        = "t.account_id = :acc";
            $params[':acc'] = (int)$filters['account_id'];
        }
        if (!empty($filters['category_id'])) {
            $where[]        = "t.category_id = :cat";
            $params[':cat'] = (int)$filters['category_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[]        = "date(t.date) >= date(:df)";
            $params[':df']  = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[]        = "date(t.date) <= date(:dt)";
            $params[':dt']  = $filters['date_to'];
        }

        $sql = "
          SELECT t.id,
                 t.date,
                 a.name        AS account,
                 c.name        AS category,
                 c.type        AS category_type,
                 t.description,
                 t.amount,
                 t.notes,
                 t.exclude_from_ca
          FROM transactions t
          JOIN accounts a ON a.id = t.account_id AND a.user_id = t.user_id
          LEFT JOIN categories c ON c.id = t.category_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY date(t.date) ASC, t.id ASC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /* =========================
       Rapports
       ========================= */
    public function getCategoryTotals(int $userId, ?string $from = null, ?string $to = null): array
    {
        $params = [':u' => $userId];
        $dateWhere = '';
        if ($from) {
            $dateWhere .= " AND date(t.date)>=date(:from)";
            $params[':from'] = $from;
        }
        if ($to) {
            $dateWhere .= " AND date(t.date)<=date(:to)";
            $params[':to'] = $to;
        }
        $sql = "
          SELECT c.id,c.name,c.type,
                 COALESCE(SUM(t.amount),0) AS total,
                 COUNT(t.id) AS txn_count
          FROM categories c
          LEFT JOIN transactions t
                 ON t.category_id=c.id
                AND t.user_id=c.user_id
                $dateWhere
          WHERE c.user_id=:u
          GROUP BY c.id
          ORDER BY ABS(total) DESC, c.name COLLATE NOCASE
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getUncategorizedTotals(int $userId, ?string $from = null, ?string $to = null): array
    {
        $params = [':u' => $userId];
        $where  = "t.category_id IS NULL AND t.user_id=:u";
        if ($from) {
            $where .= " AND date(t.date)>=date(:from)";
            $params[':from'] = $from;
        }
        if ($to) {
            $where .= " AND date(t.date)<=date(:to)";
            $params[':to'] = $to;
        }
        $sql = "
          SELECT COALESCE(SUM(t.amount),0) AS total, COUNT(t.id) AS txn_count
          FROM transactions t
          WHERE $where
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'txn_count'=>0];
    }

    public function getAccountTotals(int $userId, ?string $from = null, ?string $to = null): array
    {
        $params = [':u' => $userId];
        $dateWhere = '';
        if ($from) {
            $dateWhere .= " AND date(t.date)>=date(:from)";
            $params[':from'] = $from;
        }
        if ($to) {
            $dateWhere .= " AND date(t.date)<=date(:to)";
            $params[':to'] = $to;
        }
        $sql = "
          SELECT a.id,a.name,
                 COALESCE(SUM(t.amount),0) AS total,
                 COALESCE(SUM(CASE WHEN t.amount>=0 THEN t.amount END),0) AS credits,
                 COALESCE(SUM(CASE WHEN t.amount<0 THEN -t.amount END),0) AS debits,
                 COUNT(t.id) AS txn_count
          FROM accounts a
          LEFT JOIN transactions t
            ON t.account_id=a.id AND t.user_id=a.user_id
            $dateWhere
          WHERE a.user_id=:u
          GROUP BY a.id
          ORDER BY a.name COLLATE NOCASE
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMonthlyFlows(int $userId, int $months = 12, ?string $to = null): array
    {
        $to    = $to ?: date('Y-m-d');
        $start = (new \DateTimeImmutable($to))
            ->modify('-' . ($months - 1) . ' months')
            ->format('Y-m-01');

        $sql = "
          SELECT strftime('%Y-%m', t.date) AS ym,
                 SUM(CASE WHEN t.amount>=0 THEN t.amount ELSE 0 END) AS credits,
                 SUM(CASE WHEN t.amount<0 THEN -t.amount ELSE 0 END) AS debits,
                 SUM(t.amount) AS net
          FROM transactions t
          WHERE t.user_id=:u
            AND date(t.date)>=date(:start)
            AND date(t.date)<=date(:to)
          GROUP BY ym
          ORDER BY ym ASC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':u' => $userId, ':start' => $start, ':to' => $to]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}