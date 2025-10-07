<?php
declare(strict_types=1);

namespace App;

use PDO;

class MicroActivityRepository
{
    public function __construct(private PDO $pdo) {}

    /** Retourne toutes les activités / barèmes */
    public function listAll(): array
    {
        $st = $this->pdo->query("SELECT * FROM micro_activity_rates ORDER BY code");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Cherche une activité par code */
    public function getByCode(string $code): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM micro_activity_rates WHERE code=:c LIMIT 1");
        $st->execute([':c'=>$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}