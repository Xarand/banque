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

    public function createAccount(int $userId, string $name, string $currency='EUR', string $type=null, float $initial=0.0): int
    {
        $name = trim($name);
        if ($name === '') throw new RuntimeException("Nom requis.");
        $st = $this->pdo->prepare("SELECT 1 FROM accounts WHERE user_id=:u AND lower(name)=lower(:n)");
        $st->execute([':u'=>$userId, ':n'=>$name]);
        if ($st->fetchColumn()) throw new RuntimeException("Nom déjà utilisé.");
        $ins = $this->pdo->prepare("
          INSERT INTO accounts(user_id,name,type,currency,initial_balance)
          VALUES(:u,:n,:t,:c,:b)
        ");
        $ins->execute([':u'=>$userId, ':n'=>$name, ':t'=>$type, ':c'=>$currency, ':b'=>$initial]);
        return (int)$this->pdo->lastInsertId();
    }

    public function listAccounts(int $userId): array
    {
        $sql = "
          SELECT a.*,
                 (a.initial_balance + COALESCE((SELECT SUM(amount) FROM transactions t WHERE t.account_id=a.id AND t.user_id=:u),0)) AS current_balance
          FROM accounts a
          WHERE a.user_id=:u
          ORDER BY a.name COLLATE NOCASE ASC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':u'=>$userId]);
        return $st->fetchAll() ?: [];
    }

    public function addTransaction(int $userId, int $accountId, string $date, float $amount, string $desc=''): int
    {
        $chk = $this->pdo->prepare("SELECT 1 FROM accounts WHERE id=:a AND user_id=:u");
        $chk->execute([':a'=>$accountId, ':u'=>$userId]);
        if (!$chk->fetchColumn()) throw new RuntimeException("Compte introuvable ou interdit.");
        $st = $this->pdo->prepare("
          INSERT INTO transactions(user_id,account_id,date,description,amount)
          VALUES(:u,:a,:d,:ds,:amt)
        ");
        $st->execute([':u'=>$userId, ':a'=>$accountId, ':d'=>$date, ':ds'=>$desc ?: null, ':amt'=>$amount]);
        return (int)$this->pdo->lastInsertId();
    }

    public function listTransactions(int $userId, ?int $accountId=null): array
    {
        $where = "t.user_id=:u";
        $params = [':u'=>$userId];
        if ($accountId) { $where .= " AND t.account_id=:a"; $params[':a']=$accountId; }
        $sql = "
          SELECT t.*, a.name AS account_name
          FROM transactions t
          JOIN accounts a ON a.id=t.account_id AND a.user_id=t.user_id
          WHERE $where
          ORDER BY date(t.date) DESC, t.id DESC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll() ?: [];
    }
}