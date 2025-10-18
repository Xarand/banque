<?php
declare(strict_types=1);

/**
 * Inclusion facultative pour migrations automatiques au chargement.
 * Dans index.php :
 *   require __DIR__ . '/../scripts/auto_migrate.php';
 *
 * Ce script fait un sous-ensemble des migrations (ajout colonnes + tables micro).
 * Pour opérations plus poussées (suppression UNIQUE), utiliser scripts/migrate.php.
 */

$__auto_migrate_root = dirname(__DIR__);
$__auto_db = $__auto_migrate_root . '/data/finance.db';
if (!is_file($__auto_db)) {
    return;
}
$__pdo = new PDO('sqlite:' . $__auto_db);
$__pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function __am_has_col(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->query("PRAGMA table_info(".$table.")");
    foreach ($st as $row) {
        if (strcasecmp((string)$row['name'], $col) === 0) return true;
    }
    return false;
}
function __am_table_exists(PDO $pdo, string $table): bool {
    $st=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t");
    $st->execute([':t'=>$table]);
    return (bool)$st->fetchColumn();
}

function run_migrations(): void {
    global $__pdo;
    // Colonnes transactions
    if (__am_table_exists($__pdo,'transactions')) {
        $adds = [
            'category_id' => 'ALTER TABLE transactions ADD COLUMN category_id INTEGER NULL;',
            'budget_id'   => 'ALTER TABLE transactions ADD COLUMN budget_id INTEGER NULL;',
            'include_in_turnover' => 'ALTER TABLE transactions ADD COLUMN include_in_turnover INTEGER NOT NULL DEFAULT 1;'
        ];
        foreach ($adds as $c=>$sql) {
            if (!__am_has_col($__pdo,'transactions',$c)) {
                try { $__pdo->exec($sql); } catch (Throwable) {}
            }
        }
    }
    // Tables micro
    if (!__am_table_exists($__pdo,'micro_rates')) {
        $__pdo->exec("CREATE TABLE micro_rates (
            year INTEGER NOT NULL,
            activity_type TEXT NOT NULL CHECK(activity_type IN ('vente','service','liberale')),
            social_rate REAL NOT NULL,
            income_tax_rate REAL NOT NULL,
            PRIMARY KEY(year, activity_type)
        );");
    }
    if (!__am_table_exists($__pdo,'micro_entreprises')) {
        $__pdo->exec("CREATE TABLE micro_entreprises (
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
        );");
    }
    if (!__am_table_exists($__pdo,'micro_contribution_deadlines')) {
        $__pdo->exec("CREATE TABLE micro_contribution_deadlines (
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
        );");
    }
    // Taux par défaut année courante
    $year=(int)date('Y');
    $chk=$__pdo->prepare("SELECT 1 FROM micro_rates WHERE year=:y AND activity_type=:t");
    $ins=$__pdo->prepare("INSERT INTO micro_rates(year,activity_type,social_rate,income_tax_rate) VALUES(:y,:t,:s,:i)");
    $defs=[['vente',0.1230,0.0100],['service',0.2120,0.0170],['liberale',0.2110,0.0220]];
    foreach($defs as [$type,$soc,$ir]) {
        $chk->execute([':y'=>$year, ':t'=>$type]);
        if (!$chk->fetchColumn()) {
            try { $ins->execute([':y'=>$year,':t'=>$type,':s'=>$soc,':i'=>$ir]); } catch (Throwable) {}
        }
    }
}

run_migrations();