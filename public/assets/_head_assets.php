<?php
declare(strict_types=1);

// Aide cache-busting: ajoute ?v=filemtime
function asset(string $rel): string {
    $file = __DIR__ . '/' . $rel;
    $ver = is_file($file) ? (string)filemtime($file) : '1';
    return $rel . '?v=' . $ver;
}
?>
<!-- Assets communs -->
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/checkboxes.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/theme-overrides.css') ?>">

<script src="<?= asset('assets/chart_theme.js') ?>" defer></script>