<?php
declare(strict_types=1);

namespace App\Security;

use PDO;
use DateTimeImmutable;
use RuntimeException;

class AuthService
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /* ========== Password policy ========== */
    public static function validatePasswordPolicy(string $pwd): ?string
    {
        if (strlen($pwd) < 10) return "Mot de passe trop court (≥10).";
        if (!preg_match('/[A-Za-z]/', $pwd)) return "Doit contenir une lettre.";
        if (!preg_match('/\d/', $pwd)) return "Doit contenir un chiffre.";
        return null;
    }

    /* ========== Rate limiting ========== */
    public function isTemporarilyBlocked(?string $email, string $ip): bool
    {
        $window = "datetime('now','-15 minutes')";
        $eCount = 0;
        if ($email) {
            $st = $this->pdo->prepare("
                SELECT COUNT(*) FROM login_attempts
                WHERE email=:e AND success=0
                  AND created_at > $window
            ");
            $st->execute([':e'=>$email]);
            $eCount = (int)$st->fetchColumn();
        }
        $st2 = $this->pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE ip=:ip AND success=0
              AND created_at > $window
        ");
        $st2->execute([':ip'=>$ip]);
        $ipCount = (int)$st2->fetchColumn();

        return ($eCount >= 8) || ($ipCount >= 12);
    }

    public function logAttempt(?int $userId, ?string $email, string $ip, string $ua, bool $success): void
    {
        $st = $this->pdo->prepare("
          INSERT INTO login_attempts(user_id,email,ip,user_agent,success)
          VALUES(:u,:e,:ip,:ua,:s)
        ");
        $st->execute([
            ':u'=>$userId,
            ':e'=>$email,
            ':ip'=>$ip,
            ':ua'=>mb_substr($ua,0,255),
            ':s'=>$success ? 1 : 0
        ]);
    }

    /* ========== Remember me tokens ========== */
    public function createRememberMeToken(int $userId, int $days = 30): string
    {
        $selector  = bin2hex(random_bytes(9));       // identifiant court
        $validator = bin2hex(random_bytes(32));      // secret utilisateur
        $hash      = password_hash($validator, PASSWORD_DEFAULT);
        $expires   = (new DateTimeImmutable("+$days days"))->format('Y-m-d H:i:s');

        $st = $this->pdo->prepare("
          INSERT INTO auth_tokens(user_id,selector,validator_hash,expires_at)
          VALUES(:u,:sel,:val,:exp)
        ");
        $st->execute([
            ':u'=>$userId,
            ':sel'=>$selector,
            ':val'=>$hash,
            ':exp'=>$expires
        ]);
        return $selector . ':' . $validator;
    }

    public function consumeRememberMeToken(string $raw): ?int
    {
        if (!str_contains($raw, ':')) return null;
        [$selector,$validator] = explode(':',$raw,2);
        if (!preg_match('/^[a-f0-9]+$/',$selector) || !preg_match('/^[a-f0-9]+$/',$validator)) {
            return null;
        }
        $st = $this->pdo->prepare("
          SELECT id,user_id,validator_hash,expires_at
          FROM auth_tokens
          WHERE selector=:s
          LIMIT 1
        ");
        $st->execute([':s'=>$selector]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        if (strtotime($row['expires_at']) < time()) {
            $this->deleteTokenId((int)$row['id']);
            return null;
        }
        if (!password_verify($validator, $row['validator_hash'])) {
            $this->deleteTokenId((int)$row['id']);
            return null;
        }
        $uid = (int)$row['user_id'];
        $this->deleteTokenId((int)$row['id']); // rotation
        return $uid;
    }

    public function deleteTokenId(int $id): void
    {
        $st = $this->pdo->prepare("DELETE FROM auth_tokens WHERE id=:id");
        $st->execute([':id'=>$id]);
    }

    public function clearUserTokens(int $userId): void
    {
        $st = $this->pdo->prepare("DELETE FROM auth_tokens WHERE user_id=:u");
        $st->execute([':u'=>$userId]);
    }

    public function garbageCollectTokens(): void
    {
        $this->pdo->exec("
          DELETE FROM auth_tokens
          WHERE expires_at < datetime('now')
        ");
    }
}