<?php
// Script de migration (optionnel) pour convertir les anciens types vers les nouveaux et
// mettre à jour la contrainte CHECK de la table accounts.
// Usage: php scripts/migrate_types.php
declare(strict_types=1);

$dbPath = __DIR__ . '/../data/finance.db';
if (!file_exists($dbPath)) {
    echo "Aucun fichier $dbPath. Rien à migrer.\n";
    exit(0);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec('PRAGMA foreign_keys = OFF;');
$pdo->beginTransaction();

try {
    // Crée une nouvelle table avec la nouvelle contrainte CHECK
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accounts_new (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          name TEXT NOT NULL UNIQUE,
          type TEXT NOT NULL CHECK (type IN ('Compte courant','Compte professionnel','Placement','Espèces')),
          currency TEXT NOT NULL DEFAULT 'EUR',
          initial_balance REAL NOT NULL DEFAULT 0
        );
    ");

    // Copie des données en mappant les anciens types vers les nouveaux
    // Ancien -> Nouveau:
    // checking -> Compte courant
    // savings -> Placement
    // investment -> Placement
    // cash -> Espèces
    // credit -> Compte courant  (ajustez ici si besoin)
    $pdo->exec("
        INSERT INTO accounts_new (id, name, type, currency, initial_balance)
        SELECT id,
               name,
               CASE LOWER(type)
                 WHEN 'checking'   THEN 'Compte courant'
                 WHEN 'savings'    THEN 'Placement'
                 WHEN 'investment' THEN 'Placement'
                 WHEN 'cash'       THEN 'Espèces'
                 WHEN 'credit'     THEN 'Compte courant'
                 ELSE type
               END AS type,
               currency,
               initial_balance
        FROM accounts;
    ");

    // Remplace l'ancienne table
    $pdo->exec("DROP TABLE accounts;");
    $pdo->exec("ALTER TABLE accounts_new RENAME TO accounts;");

    // Réactiver les clés étrangères et valider
    $pdo->commit();
    $pdo->exec('PRAGMA foreign_keys = ON;');

    echo "Migration terminée avec succès.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "Echec de migration: " . $e->getMessage() . "\n";
    exit(1);
}