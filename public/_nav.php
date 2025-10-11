<?php
declare(strict_types=1);

/**
 * Navigation + liens CSS.
 * - Ajout “Rapports” si reports.php existe.
 */

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

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
if (is_file($overridesCssPath)) {
    $v3 = @filemtime($overridesCssPath);
    if ($v3) $overridesHref .= '?v=' . $v3;
}

// Email utilisateur (optionnel)
$navUserEmail = '';
try {
    if (is_callable([\App\Util::class, 'currentUserEmail'])) {
        $navUserEmail = (string)call_user_func([\App\Util::class, 'currentUserEmail']);
    } else {
        if (class_exists(\App\Util::class) && is_callable([\App\Util::class, 'startSession'])) {
            \App\Util::startSession();
        } else {
            if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        }
        foreach ([
            $_SESSION['user']['email'] ?? null,
            $_SESSION['user_email'] ?? null,
            $_SESSION['email'] ?? null,
        ] as $cand) { if (is_string($cand) && $cand !== '') { $navUserEmail = $cand; break; } }
    }
} catch (\Throwable $e) { /* ignore */ }

// CSS (compat si la page n'inclut pas _head_assets.php)
echo '<link rel="stylesheet" href="' . h($themeHref) . '">' . PHP_EOL;
echo '<link rel="stylesheet" href="' . h($checkboxHref) . '">' . PHP_EOL;
echo '<link rel="stylesheet" href="' . h($overridesHref) . '">' . PHP_EOL;

if (!function_exists('nav_active')) {
    function nav_active(string $file): string {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $cur = basename(parse_url($uri, PHP_URL_PATH) ?: '');
        return ($cur === $file) ? ' active' : '';
    }
}
?>
<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom mb-3">
  <div class="container">
    <a class="navbar-brand" href="index.php">Banque</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Menu">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link<?= nav_active('index.php') ?>" href="index.php">Tableau</a></li>
        <li class="nav-item"><a class="nav-link<?= nav_active('accounts.php') ?>" href="accounts.php">Comptes</a></li>
        <li class="nav-item"><a class="nav-link<?= nav_active('micro_index.php') ?>" href="micro_index.php">Micro</a></li>
        <?php if (is_file(__DIR__ . '/reports.php')): ?>
          <li class="nav-item"><a class="nav-link<?= nav_active('reports.php') ?>" href="reports.php">Rapports</a></li>
        <?php endif; ?>
        <?php if (is_file(__DIR__ . '/settings.php')): ?>
          <li class="nav-item"><a class="nav-link<?= nav_active('settings.php') ?>" href="settings.php">Réglages</a></li>
        <?php endif; ?>
        <?php if (is_file(__DIR__ . '/settings_theme.php')): ?>
          <li class="nav-item"><a class="nav-link<?= nav_active('settings_theme.php') ?>" href="settings_theme.php">Thème</a></li>
        <?php endif; ?>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <?php if ($navUserEmail !== ''): ?>
          <span class="navbar-text small text-muted d-none d-md-inline"><?= h($navUserEmail) ?></span>
        <?php endif; ?>
        <?php if (is_file(__DIR__ . '/logout.php')): ?>
          <a class="btn btn-sm btn-outline-secondary" href="logout.php">Déconnexion</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>