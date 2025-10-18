<?php
declare(strict_types=1);

/**
 * Migration idempotente pour l'application de gestion de comptes bancaires.
 *
 * USAGE:
 *   php scripts/migrate.php
 *   php scripts/migrate.php --allow-duplicate-accounts
 *
 * ACTIONS:
 *  - Ajoute les colonnes manquantes dans transactions:
 *      category_id INTEGER NULL
 *      budget_id   INTEGER NULL
 *      include_in_turnover INTEGER NOT NULL DEFAULT 1
 *  - Crée les tables micro_* si absentes
 *  - Crée la table micro_rates + insère les taux par défaut de l'année courante si absents
 *  - (Optionnel) supprime la contrainte UNIQUE sur accounts.name si --allow-duplicate-accounts
 *
 * Redéclenchement: sûr (idempotent). Chaque étape vérifie l'existence avant modification.
 */

$allowDuplicateAccounts = in_array('--allow-duplicate-accounts', $argv, true);

$root = dirname(__DIR__);
$dbFile = $root . '/data/finance.db';

if (!is_file($dbFile)) {
    fwrite(STDERR, "Base introuvable: $dbFile\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function hasColumn(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->query("PRAGMA table_info(" . $table . ")");
    foreach ($st as $row) {
        if (strcasecmp((string)$row['name'], $col) === 0) return true;
    }
    return false;
}
function tableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t");
    $st->execute([':t'=>$table]);
    return (bool)$st->fetchColumn();
}
function getCreateSQL(PDO $pdo, string $table): ?string {
    $st = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=:t");
    $st->execute([':t'=>$table]);
    $sql = $st->fetchColumn();
    return $sql !== false ? (string)$sql : null;
}
function runSilent(PDO $pdo, string $sql): bool {
    try { $pdo->exec($sql); return true; } catch (Throwable) { return false; }
}

$log = [];
$log[] = "=== Migration démarrée ".date('Y-m-d H:i:s')." ===";
$log[] = "DB: $dbFile";

/* 1. Colonnes transactions */
if (!tableExists($pdo,'transactions')) {
    $log[] = "ATTENTION: table 'transactions' introuvable. Aucune colonne ajoutée.";
} else {
    $colsToAdd = [
        'category_id' => 'ALTER TABLE transactions ADD COLUMN category_id INTEGER NULL;',
        'budget_id'   => 'ALTER TABLE transactions ADD COLUMN budget_id INTEGER NULL;',
        'include_in_turnover' => 'ALTER TABLE transactions ADD COLUMN include_in_turnover INTEGER NOT NULL DEFAULT 1;'
    ];
    foreach ($colsToAdd as $col => $stmt) {
        if (hasColumn($pdo,'transactions',$col)) {
            $log[] = "SKIP  transactions.$col (existe déjà)";
        } else {
            if (runSilent($pdo, $stmt)) {
                $log[] = "ADDED transactions.$col";
            } else {
                $log[] = "ERREUR ajout colonne $col (peut-être verrou DB)";
            }
        }
    }
}

/* 2. Tables micro */
$microTables = [
    'micro_rates' => "CREATE TABLE micro_rates (
        year INTEGER NOT NULL,
        activity_type TEXT NOT NULL CHECK(activity_type IN ('vente','service','liberale')),
        social_rate REAL NOT NULL,
        income_tax_rate REAL NOT NULL,
        PRIMARY KEY(year, activity_type)
    );",
    'micro_entreprises' => "CREATE TABLE micro_entreprises (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        account_id INTEGER NOT NULL UNIQUE,
        business_name TEXT NOT NULL,
        creation_date TEXT NOT NULL,
        activity_type TEXT NOT NULL CHECK(activity_type IN ('vente','service','liberale')),
        income_tax_flat INTEGER NOT NULL CHECK(income_tax_flat IN (0,1)),
        contribution_period TEXT NOT NULL CHECK(contribution_period IN ('mensuelle','trimestrielle')),
        created_at TEXT NOT NULL DEFAULT (date('now')),
        updated_at TEXT NOT NULL DEFAULT (date('now')),
        FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
    );",
    'micro_contribution_deadlines' => "CREATE TABLE micro_contribution_deadlines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        micro_id INTEGER NOT NULL,
        period_label TEXT NOT NULL,
        period_start TEXT NOT NULL,
        period_end TEXT NOT NULL,
        due_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','skipped')),
        turnover REAL NOT NULL DEFAULT 0,
        social_due REAL NULL,
        income_tax_due REAL NULL,
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        FOREIGN KEY(micro_id) REFERENCES micro_entreprises(id) ON DELETE CASCADE,
        UNIQUE(micro_id, period_label)
    );"
];

foreach ($microTables as $tbl => $sql) {
    if (tableExists($pdo, $tbl)) {
        $log[] = "SKIP  table $tbl (existe)";
    } else {
        if (runSilent($pdo, $sql)) {
            $log[] = "CREATED table $tbl";
        } else {
            $log[] = "ERREUR création table $tbl";
        }
    }
}

/* 3. Taux par défaut micro pour année courante */
$year = (int)date('Y');
if (tableExists($pdo,'micro_rates')) {
    $defaults = [
        ['vente',    0.1230, 0.0100],
        ['service',  0.2120, 0.0170],
        ['liberale', 0.2110, 0.0220],
    ];
    $chk = $pdo->prepare("SELECT 1 FROM micro_rates WHERE year=:y AND activity_type=:t");
    $ins = $pdo->prepare("INSERT INTO micro_rates(year,activity_type,social_rate,income_tax_rate) VALUES(:y,:t,:s,:i)");
    foreach ($defaults as [$type,$soc,$ir]) {
        $chk->execute([':y'=>$year, ':t'=>$type]);
        if ($chk->fetchColumn()) {
            $log[] = "SKIP  micro_rates ($year,$type)";
        } else {
            $ins->execute([':y'=>$year, ':t'=>$type, ':s'=>$soc, ':i'=>$ir]);
            $log[] = "ADDED micro_rates ($year,$type) social=$soc IR=$ir";
        }
    }
} else {
    $log[] = "INFO micro_rates absente (pas de taux insérés)";
}

/* 4. Option: supprimer UNIQUE sur accounts.name */
if ($allowDuplicateAccounts) {
    if (!tableExists($pdo,'accounts')) {
        $log[] = "INFO pas de table accounts pour supprimer UNIQUE.";
    } else {
        $createSQL = getCreateSQL($pdo,'accounts');
        if ($createSQL && preg_match('/\bUNIQUE\b/i', $createSQL) && preg_match('/name\s+TEXT[^,]*UNIQUE/i',$createSQL)) {
            $log[] = "INFO reconstruction de accounts sans contrainte UNIQUE(name)";
            $pdo->beginTransaction();
            try {
                // Extraire colonnes (approche simple : on reconstruit sans UNIQUE)
                // Hypothèse colonnes standard: id, name, type, currency, initial_balance
                $pdo->exec("CREATE TABLE accounts_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    type TEXT,
                    currency TEXT,
                    initial_balance REAL NOT NULL DEFAULT 0
                );");
                $pdo->exec("INSERT INTO accounts_new(id,name,type,currency,initial_balance)
                            SELECT id,name,type,currency,initial_balance FROM accounts;");
                $pdo->exec("DROP TABLE accounts;");
                $pdo->exec("ALTER TABLE accounts_new RENAME TO accounts;");
                $pdo->commit();
                $log[] = "REBUILT accounts (UNIQUE supprimé)";
            } catch (Throwable $e) {
                $pdo->rollBack();
                $log[] = "ERREUR reconstruction accounts: ".$e->getMessage();
            }
        } else {
            $log[] = "SKIP  suppression UNIQUE: soit absent, soit déjà retiré.";
        }
    }
} else {
    $log[] = "INFO (pas de suppression UNIQUE sur accounts.name — option non fournie)";
}

/* 5. Résumé */
$log[] = "=== Migration terminée ".date('Y-m-d H:i:s')." ===";

echo implode(PHP_EOL, $log).PHP_EOL;