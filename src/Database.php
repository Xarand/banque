<?php
declare(strict_types=1);

namespace App;

use PDO;

class Database
{
    private PDO $pdo;

    public function __construct(?string $file=null)
    {
        $file ??= __DIR__.'/../data/finance.db';
        $this->pdo = new PDO('sqlite:'.$file);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("PRAGMA foreign_keys = ON");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}