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
    public function createAccount(int $userId, string $name, string $currency='EUR', ?string $type=null, float $initial=0.0): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException("Nom de compte requis.");
        }
        $check = $this->pdo->prepare("SELECT 1 FROM accounts WHERE user_id=:u AND lower(name)=lower(:n)");
        $check->execute([':u'=>$userId, ':n'=>$name]);
        if ($check->fetchColumn()) {
            throw new RuntimeException("Ce nom de compte existe déjà.");
        }
        $stmt = $this->pdo->prepare("
          INSERT INTO accounts(user_id,name,type,currency,initial_balance)
          VALUES(:u,:n,:t,:c,:b)
        ");
        $stmt->execute([
            ':u'=>$userId,
            ':n'=>$name,
            ':t'=>$type,
            ':c'=>$currency,
            ':b'=>$initial
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
        $st->execute([':u'=>$userId]);
        return $st->fetchAll() ?: [];
    }

    /* =========================
       Catégories
       ========================= */
    public function createCategory(int $userId, string $name, ?string $type=null): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException("Nom de catégorie requis.");
        }
        if ($type !== null && !in_array($type, ['income','expense'], true)) {
            $type = null; // Sécurise contre des valeurs arbitraires
        }
        $check = $this->pdo->prepare("SELECT 1 FROM categories WHERE user_id=:u AND lower(name)=lower(:n)");
        $check->execute([':u'=>$userId, ':n'=>$name]);
        if ($check->fetchColumn()) {
            throw new RuntimeException("Catégorie déjà existante.");
        }
        $st = $this->pdo->prepare("
          INSERT INTO categories(user_id,name,type)
          VALUES(:u,:n,:t)
        ");
        $st->execute([':u'=>$userId, ':n'=>$name, ':t'=>$type]);
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
        $st->execute([':u'=>$userId]);
        return $st->fetchAll() ?: [];
    }

    /* =========================
       Transactions (CRUD)
       ========================= */

    /**
     * @param string|null $direction 'credit'|'debit'|null (utilisé si pas de catégorie ou catégorie sans type)
     */
    public function addTransaction(
        int $userId,
        int $accountId,
        string $date,
        float $amount,
        string $desc='',
        ?int $categoryId=null,
        ?string $notes=null,
        ?string $direction=null
    ): int {
        $acc = $this->pdo->prepare("SELECT 1 FROM accounts WHERE id=:a AND user_id=:u");
        $acc->execute([':a'=>$accountId, ':u'=>$userId]);
        if (!$acc->fetchColumn()) {
            throw new RuntimeException("Compte introuvable.");
        }

        $catType = null;
        if ($categoryId !== null) {
            $cat = $this->pdo->prepare("SELECT type FROM categories WHERE id=:c AND user_id=:u");
            $cat->execute([':c'=>$categoryId, ':u'=>$userId]);
            $catType = $cat->fetchColumn();
            if ($catType === false) {
                throw new RuntimeException("Catégorie invalide.");
            }
            if (!in_array($catType, ['income','expense',null], true)) {
                $catType = null;
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
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
            // Pas de type de catégorie => utiliser direction
            if ($direction === 'debit' && $normalized > 0) {
                $normalized = -$normalized;
            } elseif ($direction === 'credit' && $normalized < 0) {
                $normalized = abs($normalized);
            } elseif ($direction === null) {
                // si rien fourni, on garde le signe que l'utilisateur a entré
                $normalized = $amount;
            }
        }

        $st = $this->pdo->prepare("
          INSERT INTO transactions(user_id,account_id,date,description,amount,category_id,notes)
          VALUES(:u,:a,:d,:ds,:amt,:cat,:notes)
        ");
        $st->execute([
            ':u'=>$userId,
            ':a'=>$accountId,
            ':d'=>$date,
            ':ds'=>$desc ?: null,
            ':amt'=>$normalized,
            ':cat'=>$categoryId,
            ':notes'=>$notes ?: null
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getTransactionById(int $userId, int $id): ?array
    {
        $st = $this->pdo->prepare("
          SELECT t.*,
                 a.name AS account_name,
                 c.name AS category_name,
                 c.type AS category_type
          FROM transactions t
          JOIN accounts a ON a.id=t.account_id AND a.user_id=:u
          LEFT JOIN categories c ON c.id=t.category_id
          WHERE t.id=:id AND t.user_id=:u
          LIMIT 1
        ");
        $st->execute([':id'=>$id, ':u'=>$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * @param string|null $direction 'credit'|'debit'|null (utilisé si pas de catégorie ou catégorie sans type)
     */
    public function updateTransaction(
        int $userId,
        int $id,
        int $accountId,
        string $date,
        float $amount,
        string $desc='',
        ?int $categoryId=null,
        ?string $notes=null,
        ?string $direction=null
    ): void {
        $existing = $this->getTransactionById($userId, $id);
        if (!$existing) {
            throw new RuntimeException("Transaction introuvable.");
        }

        $acc = $this->pdo->prepare("SELECT 1 FROM accounts WHERE id=:a AND user_id=:u");
        $acc->execute([':a'=>$accountId, ':u'=>$userId]);
        if (!$acc->fetchColumn()) {
            throw new RuntimeException("Compte invalide.");
        }

        $catType = null;
        if ($categoryId !== null) {
            $cat = $this->pdo->prepare("SELECT type FROM categories WHERE id=:c AND user_id=:u");
            $cat->execute([':c'=>$categoryId, ':u'=>$userId]);
            $catType = $cat->fetchColumn();
            if ($catType === false) {
                throw new RuntimeException("Catégorie invalide.");
            }
            if (!in_array($catType, ['income','expense',null], true)) {
                $catType = null;
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
            throw new RuntimeException("Date invalide.");
        }
        if ($amount == 0.0) {
            throw new RuntimeException("Montant nul interdit.");
        }

        $normalized = abs($amount);

        if ($catType === 'expense') {
            $normalized = -$normalized;
        } elseif ($catType === 'income') {
            // positif
        } else {
            if ($direction === 'debit') {
                $normalized = -$normalized;
            } elseif ($direction === 'credit') {
                $normalized = +$normalized;
            } else {
                // pas de direction fournie -> garder signe original
                $normalized = $amount;
            }
        }

        $st = $this->pdo->prepare("
          UPDATE transactions
             SET account_id=:a,
                 date=:d,
                 description=:ds,
                 amount=:amt,
                 category_id=:cat,
                 notes=:notes
           WHERE id=:id AND user_id=:u
        ");
        $st->execute([
            ':a'=>$accountId,
            ':d'=>$date,
            ':ds'=>$desc ?: null,
            ':amt'=>$normalized,
            ':cat'=>$categoryId,
            ':notes'=>$notes ?: null,
            ':id'=>$id,
            ':u'=>$userId
        ]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException("Mise à jour non effectuée.");
        }
    }

    public function deleteTransaction(int $userId, int $id): void
    {
        $st = $this->pdo->prepare("DELETE FROM transactions WHERE id=:id AND user_id=:u");
        $st->execute([':id'=>$id, ':u'=>$userId]);
    }

    /* =========================
       Recherche / Filtrage
       ========================= */
    public function searchTransactions(
        int $userId,
        array $filters,
        int $limit = 100
    ): array {
        $where = ["t.user_id = :u"];
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

        $whereSql = implode(' AND ', $where);

        $sql = "
          SELECT t.id, t.date, t.description, t.amount, t.notes,
                 a.name AS account, c.name AS category, c.type AS category_type
          FROM transactions t
          JOIN accounts a ON a.id = t.account_id AND a.user_id = t.user_id
          LEFT JOIN categories c ON c.id = t.category_id
          WHERE $whereSql
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
}