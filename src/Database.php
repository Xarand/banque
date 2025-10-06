<?php
declare(strict_types=1);

namespace App;

use PDO;

class Database
{
    private PDO $pdo;

    public function __construct(?string $path = null)
    {
        $path ??= __DIR__ . '/../data/finance.db';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("PRAGMA foreign_keys = ON");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}