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
    public function createAccount(int $userId, string $name, string $currency='EUR', string $type=null, float $initial=0.0): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException("Nom requis.");
        }
        $st = $this->pdo->prepare("SELECT 1 FROM accounts WHERE user_id=:u AND lower(name)=lower(:n)");
        $st->execute([':u'=>$userId, ':n'=>$name]);
        if ($st->fetchColumn()) {
            throw new RuntimeException("Nom déjà utilisé.");
        }
        $ins = $this->pdo->prepare("
          INSERT INTO accounts(user_id,name,type,currency,initial_balance)
          VALUES(:u,:n,:t,:c,:b)
        ");
        $ins->execute([
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
                   (SELECT SUM(amount) FROM transactions t WHERE t.account_id=a.id AND t.user_id=:u),0
                 )) AS current_balance
          FROM accounts a
          WHERE a.user_id=:u
          ORDER BY a.name COLLATE NOCASE ASC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':u'=>$userId]);
        return $st->fetchAll() ?: [];
    }

    /* =========================
       Transactions
       ========================= */
    public function addTransaction(
        int $userId,
        int $accountId,
        string $date,
        float $amount,
        string $desc='',
        ?int $categoryId = null
    ): int {
        $chk = $this->pdo->prepare("SELECT 1 FROM accounts WHERE id=:a AND user_id=:u");
        $chk->execute([':a'=>$accountId, ':u'=>$userId]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException("Compte introuvable ou interdit.");
        }

        if ($categoryId !== null) {
            $ckc = $this->pdo->prepare("SELECT 1 FROM categories WHERE id=:c AND user_id=:u");
            $ckc->execute([':c'=>$categoryId, ':u'=>$userId]);
            if (!$ckc->fetchColumn()) {
                throw new RuntimeException("Catégorie invalide.");
            }
        }

        $st = $this->pdo->prepare("
          INSERT INTO transactions(user_id,account_id,date,description,amount,category_id)
          VALUES(:u,:a,:d,:ds,:amt,:cat)
        ");
        $st->execute([
            ':u'=>$userId,
            ':a'=>$accountId,
            ':d'=>$date,
            ':ds'=>$desc ?: null,
            ':amt'=>$amount,
            ':cat'=>$categoryId
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function listTransactions(int $userId, ?int $accountId=null): array
    {
        $where = "t.user_id=:u";
        $params = [':u'=>$userId];
        if ($accountId) {
            $where .= " AND t.account_id=:a";
            $params[':a']=$accountId;
        }
        $sql = "
          SELECT t.*, a.name AS account_name, c.name AS category_name
          FROM transactions t
          JOIN accounts a ON a.id=t.account_id AND a.user_id=t.user_id
          LEFT JOIN categories c ON c.id=t.category_id
          WHERE $where
          ORDER BY date(t.date) DESC, t.id DESC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll() ?: [];
    }

    /* =========================
       Catégories
       ========================= */
    public function createCategory(int $userId, string $name, ?string $type=null): int
    {
        $name = trim($name);
        if ($name==='') {
            throw new RuntimeException("Nom de catégorie requis.");
        }
        $st = $this->pdo->prepare("SELECT 1 FROM categories WHERE user_id=:u AND lower(name)=lower(:n)");
        $st->execute([':u'=>$userId, ':n'=>$name]);
        if ($st->fetchColumn()) {
            throw new RuntimeException("Catégorie déjà existante.");
        }
        $ins = $this->pdo->prepare("
          INSERT INTO categories(user_id,name,type)
          VALUES(:u,:n,:t)
        ");
        $ins->execute([':u'=>$userId, ':n'=>$name, ':t'=>$type]);
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
}