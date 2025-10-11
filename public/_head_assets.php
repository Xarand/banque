<?php
declare(strict_types=1);
/**
 * À inclure DANS <head> pour appliquer immédiatement le thème.
 * Exemple: <?php include __DIR__.'/_head_assets.php'; ?>
 */

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// Cache-busting basé sur theme_vars et filemtime
$customHash = !empty($_COOKIE['theme_vars']) ? substr(sha1((string)$_COOKIE['theme_vars']), 0, 12) : '';

$themeHref = 'theme.php';
if ($customHash !== '') $themeHref .= '?c=' . $customHash;
$themeFile = __DIR__ . '/theme.php';
if (is_file($themeFile)) {
    $v = @filemtime($themeFile);
    $themeHref .= ($customHash ? '&' : '?') . 'v=' . ($v ?: time());
}

$checkboxCssPath = __DIR__ . '/assets/checkboxes.css';
$checkboxHref = 'assets/checkboxes.css';
if (is_file($checkboxCssPath)) {
    $v2 = @filemtime($checkboxCssPath);
    if ($v2) $checkboxHref .= '?v=' . $v2;
}

$overridesCssPath = __DIR__ . '/assets/theme-overrides.css';
$overridesHref = 'assets/theme-overrides.css';
$includeOverrides = is_file($overridesCssPath);
if ($includeOverrides) {
    $v3 = @filemtime($overridesCssPath);
    if ($v3) $overridesHref .= '?v=' . $v3;
}

// Script d’adaptation Chart.js au thème (facultatif, chargé seulement s’il existe)
$chartThemeJsPath = __DIR__ . '/assets/chart-theme.js';
$chartThemeHref = 'assets/chart-theme.js';
$includeChartTheme = is_file($chartThemeJsPath);
if ($includeChartTheme) {
    $v4 = @filemtime($chartThemeJsPath);
    if ($v4) $chartThemeHref .= '?v=' . $v4;
}

// Sortie des <link> CSS
echo '<link rel="stylesheet" href="'.h($themeHref).'">'.PHP_EOL;
echo '<link rel="stylesheet" href="'.h($checkboxHref).'">'.PHP_EOL;
if ($includeOverrides) echo '<link rel="stylesheet" href="'.h($overridesHref).'">'.PHP_EOL;

// Chart.js (CDN) + adaptation au thème (uniquement si le fichier local existe)
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>'.PHP_EOL;
if ($includeChartTheme) echo '<script src="'.h($chartThemeHref).'" defer></script>'.PHP_EOL;