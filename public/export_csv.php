<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database};

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = Util::currentUserId();

// Détecte la présence de la table categories
$hasCategories = false;
try {
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='categories' LIMIT 1");
    $st->execute();
    $hasCategories = (bool)$st->fetchColumn();
} catch (Throwable $e) {
    $hasCategories = false;
}

function parseDate(?string $s): ?string {
    if (!$s) return null;
    $s = trim($s);
    // Formats acceptés: YYYY-MM-DD ou DD/MM/YYYY
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $s)) return $s;
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    return null;
}

// Récupère les filtres (noms génériques; adapte si ton index.php envoie d’autres noms)
$accountId  = isset($_GET['account_id']) ? (int)$_GET['account_id'] : null;
$categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$dateFrom   = parseDate($_GET['from'] ?? $_GET['du'] ?? null);
$dateTo     = parseDate($_GET['to']   ?? $_GET['au'] ?? null);
$type       = $_GET['type'] ?? ''; // 'credit' | 'debit' | ''

// Optionnel: restreindre aux comptes d’une micro (si fourni)
$microId    = isset($_GET['micro_id']) ? (int)$_GET['micro_id'] : null;

// Construction SQL
$cols = [
    "t.date AS date",
    "a.name AS account",
    $hasCategories ? "c.name AS category" : "NULL AS category",
    "CASE WHEN t.amount < 0 THEN 'Débit' ELSE 'Crédit' END AS type",
    "t.description",
    "t.amount",
    "t.notes",
    "t.exclude_from_ca"
];

$sql = "
SELECT ".implode(", ", $cols)."
FROM transactions t
JOIN accounts a ON a.id = t.account_id
".($hasCategories ? "LEFT JOIN categories c ON c.id = t.category_id" : "")."
WHERE t.user_id = :u
";
$params = [':u' => $userId];

// Filtres
if ($accountId) {
    $sql .= " AND t.account_id = :acc";
    $params[':acc'] = $accountId;
}
if ($microId) {
    $sql .= " AND a.micro_enterprise_id = :mid";
    $params[':mid'] = $microId;
}
if ($hasCategories && $categoryId) {
    $sql .= " AND t.category_id = :cat";
    $params[':cat'] = $categoryId;
}
if ($dateFrom) {
    $sql .= " AND date(t.date) >= date(:df)";
    $params[':df'] = $dateFrom;
}
if ($dateTo) {
    $sql .= " AND date(t.date) <= date(:dt)";
    $params[':dt'] = $dateTo;
}
if ($type === 'credit') {
    $sql .= " AND t.amount > 0";
} elseif ($type === 'debit') {
    $sql .= " AND t.amount < 0";
}

$sql .= " ORDER BY date(t.date) ASC, t.id ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Sortie CSV
$filename = 'transactions_'.date('Ymd_His').'.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// BOM UTF-8 pour Excel
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// En-têtes
$headers = ['Date','Compte','Catégorie','Type','Description','Montant','Notes','Exclu du CA'];
fputcsv($out, $headers, ';');

// Lignes
foreach ($rows as $r) {
    fputcsv($out, [
        $r['date'],
        $r['account'],
        $r['category'],
        $r['type'],
        $r['description'],
        // Montant: on laisse brut; Excel FR affichera avec virgule selon régional
        $r['amount'],
        $r['notes'],
        $r['exclude_from_ca']
    ], ';');
}

fclose($out);
exit;