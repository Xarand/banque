<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

class UserRepository
{
    private PDO $pdo;
    public function __construct(Database|PDO $db)
    {
        $this->pdo = $db instanceof Database ? $db->pdo() : $db;
    }

    public function findByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM users WHERE lower(email)=lower(:e)");
        $st->execute([':e'=>$email]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function create(string $email, string $display, string $plain): int
    {
        $email = trim($email);
        $display = trim($display);
        if ($email === '' || $display === '' || $plain === '') {
            throw new RuntimeException('Champs requis.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email invalide.');
        }
        if (strlen($plain) < 8) {
            throw new RuntimeException('Mot de passe trop court (min 8).');
        }
        if ($this->findByEmail($email)) {
            throw new RuntimeException('Email déjà utilisé.');
        }
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        $st = $this->pdo->prepare("
          INSERT INTO users(email,password_hash,display_name)
          VALUES(:e,:h,:d)
        ");
        $st->execute([':e'=>$email, ':h'=>$hash, ':d'=>$display]);
        return (int)$this->pdo->lastInsertId();
    }

    public function verifyLogin(string $email, string $plain): ?int
    {
        $u = $this->findByEmail($email);
        if (!$u) return null;
        if (!password_verify($plain, $u['password_hash'])) {
            $this->pdo->prepare("UPDATE users SET failed_logins=failed_logins+1,updated_at=datetime('now') WHERE id=:i")
                ->execute([':i'=>$u['id']]);
            return null;
        }
        $this->pdo->prepare("UPDATE users SET failed_logins=0,last_login_at=datetime('now'),updated_at=datetime('now') WHERE id=:i")
            ->execute([':i'=>$u['id']]);
        return (int)$u['id'];
    }
}