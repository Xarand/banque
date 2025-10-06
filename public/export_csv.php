<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\{Util, Database, FinanceRepository};

Util::startSession();
Util::requireAuth();

$db   = new Database();
$repo = new FinanceRepository($db);
$userId = Util::currentUserId();

/*
 * Paramètres GET repris de index.php :
 * account_id, category_id, date_from, date_to
 */
$filterAccountId  = isset($_GET['account_id']) && $_GET['account_id'] !== '' ? (int)$_GET['account_id'] : null;
$filterCategoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
$dateFrom         = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo           = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$validDate = static fn(string $d): bool => $d === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/',$d);
if (!$validDate($dateFrom)) $dateFrom = '';
if (!$validDate($dateTo))   $dateTo   = '';

$filters = [
    'account_id'  => $filterAccountId,
    'category_id' => $filterCategoryId,
    'date_from'   => $dateFrom ?: null,
    'date_to'     => $dateTo ?: null,
];

$st = $repo->exportTransactions($userId, $filters);

/* Génération du nom de fichier */
$parts = [];
if ($filters['account_id'])  $parts[] = 'acc'.$filters['account_id'];
if ($filters['category_id']) $parts[] = 'cat'.$filters['category_id'];
if ($dateFrom)               $parts[] = 'from'.$dateFrom;
if ($dateTo)                 $parts[] = 'to'.$dateTo;
$baseName = 'transactions';
if ($parts) $baseName .= '_'.implode('_',$parts);
$filename = $baseName.'_'.date('Ymd_His').'.csv';

/* En‑têtes HTTP (streaming) */
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache');
header('Expires: 0');

/* BOM UTF‑8 pour Excel Windows */
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

/* Délimiteur ; (plus sûr en contexte FR avec virgule décimale éventuelle)
   Colonnes :
   id;date;compte;catégorie;type_categorie;type (Crédit/Débit);montant_signe;montant_absolu;description;notes
*/
fputcsv($out, [
    'id','date','compte','categorie','type_categorie',
    'type','montant_signe','montant_absolu','description','notes'
], ';');

while ($row = $st->fetch(PDO::FETCH_ASSOC)) {

    $amount    = (float)$row['amount'];
    $typeHuman = $amount >= 0 ? 'Crédit' : 'Débit';

    $line = [
        $row['id'],
        $row['date'],
        $row['account'],
        $row['category'] ?? '',
        $row['category_type'] ?? '',
        $typeHuman,
        // Montant signé (standard)
        number_format($amount, 2, '.', ''),          // format neutre machine
        number_format(abs($amount), 2, '.', ''),     // absolu
        normalizeForCsv($row['description'] ?? ''),
        normalizeForCsv($row['notes'] ?? ''),
    ];

    fputcsv($out, $line, ';');
}

fclose($out);
exit;

/**
 * Nettoie les données texte pour éviter les retours lignes ou séparateurs import gênants.
 */
function normalizeForCsv(string $v): string {
    // Supprimer CR/LF, remplacer ; doubles par virgule
    $v = str_replace(["\r","\n"], ' ', $v);
    // Optionnel: retirer un début de formule Excel potentielle (=,+,-,@)
    if (preg_match('/^[=+\-@]/', $v)) {
        $v = "'".$v;
    }
    return $v;
}