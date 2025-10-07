<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\{Util, Database, FinanceRepository};

Util::startSession();
Util::requireAuth();

$db   = new Database();
$repo = new FinanceRepository($db);
$userId = Util::currentUserId();

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

$parts = [];
if ($filters['account_id'])  $parts[] = 'acc'.$filters['account_id'];
if ($filters['category_id']) $parts[] = 'cat'.$filters['category_id'];
if ($dateFrom)               $parts[] = 'from'.$dateFrom;
if ($dateTo)                 $parts[] = 'to'.$dateTo;
$baseName = 'transactions';
if ($parts) $baseName .= '_'.implode('_',$parts);
$filename = $baseName.'_'.date('Ymd_His').'.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

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
        number_format($amount, 2, '.', ''),
        number_format(abs($amount), 2, '.', ''),
        normalizeForCsv($row['description'] ?? ''),
        normalizeForCsv($row['notes'] ?? ''),
    ];
    fputcsv($out, $line, ';');
}
fclose($out);
exit;

function normalizeForCsv(string $v): string {
    $v = str_replace(["\r","\n"], ' ', $v);
    if (preg_match('/^[=+\-@]/', $v)) {
        $v = "'".$v;
    }
    return $v;
}