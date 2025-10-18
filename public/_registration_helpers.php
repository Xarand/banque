<?php
declare(strict_types=1);

/**
 * Mapping département -> taux CMA (prestation de services)
 * Ajoutez d'autres départements / activités si nécessaire.
 */
function determineCmaRate(string $department, string $activity): float {
    $dep = strtolower(trim($department));
    $act = strtolower(trim($activity));

    // Exemple limité aux cas fournis: prestation de services
    // Vous pouvez enrichir ce tableau pour d'autres activités
    $rates = [
        // clés normalisées
        'alsace'  => ['services' => 0.00650], // 0.650%
        'moselle' => ['services' => 0.00830], // 0.830%
    ];

    if (isset($rates[$dep]) && isset($rates[$dep][$act])) {
        return (float)$rates[$dep][$act];
    }

    // fallback: 0.0 ou taux par défaut
    return 0.0;
}

/**
 * Append a registration row to a CSV file (securely)
 * fields: email, department, activity, created_at (Y-m-d H:i:s)
 * Returns true on success.
 */
function appendRegistrationCsv(string $email, string $department, string $activity, string $createdAt): bool {
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/registrations.csv';
    $isNew = !file_exists($file);

    $fp = @fopen($file, 'a');
    if (!$fp) return false;
    // exclusive lock
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }

    // BOM for Excel UTF-8 (only if new)
    if ($isNew) {
        // write header
        fputcsv($fp, ['email','department','activity','created_at'], ';');
    }
    // write row
    fputcsv($fp, [$email, $department, $activity, $createdAt], ';');

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}