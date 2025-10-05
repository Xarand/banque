<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

class Database
{
    private PDO $pdo;

    public function __construct(string $dbPath = __DIR__ . '/../data/finance.db')
    {
        $dir = \dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException("Impossible de créer le dossier data.");
        }

        $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys=ON;');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function ensureSchema(string $schemaFile): void
    {
        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new RuntimeException("schema.sql introuvable");
        }
        $this->pdo->exec($sql);
    }
}