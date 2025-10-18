<?php
declare(strict_types=1);

/**
 * Navigation + liens CSS (thème clair).
 * - Brand:
 *   - priorité au vectoriel (assets/logo.svg) pour un rendu parfait,
 *   - sinon raster avec densités 1x/2x/3x: logo.(png|webp|jpg|jpeg) + logo@2x.ext + logo@3x.ext
 *   - cache-busting via filemtime
 * - Onglets: Comptes, Transactions, Cotisations, Rapports, Réglages, Thème.
 */

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// Cache-busting thème
$customHash = !empty($_COOKIE['theme_vars']) ? substr(sha1((string)$_COOKIE['theme_vars']), 0, 12) : '';
$themeHref = 'theme.php';
if ($customHash !== '') $themeHref .= '?c=' . $customHash;
$themeFile = __DIR__ . '/theme.php';
if (is_file($themeFile)) {
    $v = @filemtime($themeFile);
    $themeHref .= ($customHash ? '&' : '?') . 'v=' . ($v ?: time());
}

// assets CSS
$checkboxCssPath = __DIR__ . '/assets/checkboxes.css';
$checkboxHref = 'assets/checkboxes.css';
if (is_file($checkboxCssPath)) { $v2 = @filemtime($checkboxCssPath); if ($v2) $checkboxHref .= '?v=' . $v2; }

$overridesCssPath = __DIR__ . '/assets/theme-overrides.css';
$overridesHref = 'assets/theme-overrides.css';
if (is_file($overridesCssPath)) { $v3 = @filemtime($overridesCssPath); if ($v3) $overridesHref .= '?v=' . $v3; }

// CSS (compat si la page n'inclut pas _head_assets.php)
echo '<link rel="stylesheet" href="' . h($themeHref) . '">' . PHP_EOL;
echo '<link rel="stylesheet" href="' . h($checkboxHref) . '">' . PHP_EOL;
echo '<link rel="stylesheet" href="' . h($overridesHref) . '">' . PHP_EOL;

// Helper onglet actif
if (!function_exists('nav_active')) {
    function nav_active(string $file): string {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $cur = basename(parse_url($uri, PHP_URL_PATH) ?: '');
        return ($cur === $file) ? ' active' : '';
    }
}

// Brand: priorité SVG, sinon raster avec 1x/2x/3x
$brandHtml = 'Micro‑Pilote';
$svgPath = __DIR__ . '/assets/logo.svg';
if (is_file($svgPath)) {
    $ver = @filemtime($svgPath);
    $brandHtml = '<img class="brand-logo" src="assets/logo.svg'.($ver?('?v='.$ver):'').'" alt="Micro‑Pilote">';
} else {
    $rasterExts = ['png','webp','jpg','jpeg'];
    $baseRel = ''; $extUsed = '';
    foreach ($rasterExts as $ext) {
        $probe = __DIR__ . '/assets/logo.'.$ext;
        if (is_file($probe)) { $baseRel = 'assets/logo.'.$ext; $extUsed = $ext; break; }
    }
    if ($baseRel !== '') {
        $v1 = @filemtime(__DIR__.'/'.$baseRel) ?: time();
        $src1x = $baseRel.'?v='.$v1;
        $srcset = [$src1x.' 1x'];
        $retina2 = __DIR__ . '/assets/logo@2x.'.$extUsed;
        $retina3 = __DIR__ . '/assets/logo@3x.'.$extUsed;
        if (is_file($retina2)) { $v2 = @filemtime($retina2) ?: $v1; $srcset[] = 'assets/logo@2x.'.$extUsed.'?v='.$v2.' 2x'; }
        if (is_file($retina3)) { $v3 = @filemtime($retina3) ?: $v1; $srcset[] = 'assets/logo@3x.'.$extUsed.'?v='.$v3.' 3x'; }
        $cls = 'brand-logo';
        if (in_array($extUsed, ['jpg','jpeg'], true)) $cls .= ' brand-logo--blend';
        $brandHtml = '<img class="'.h($cls).'" src="'.h($src1x).'" srcset="'.h(implode(', ', $srcset)).'" alt="Micro‑Pilote">';
    }
}

// Email utilisateur (si dispo) et état d'authentification
$navUserEmail = '';
try {
    if (is_callable([\App\Util::class, 'currentUserEmail'])) {
        $navUserEmail = (string)call_user_func([\App\Util::class, 'currentUserEmail']);
    } else {
        if (class_exists(\App\Util::class) && is_callable([\App\Util::class, 'startSession'])) \App\Util::startSession();
        else if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        foreach ([
            $_SESSION['user']['email'] ?? null,
            $_SESSION['user_email'] ?? null,
            $_SESSION['email'] ?? null,
        ] as $cand) { if (is_string($cand) && $cand !== '') { $navUserEmail = $cand; break; } }
    }
} catch (\Throwable $e) { /* ignore */ }

// Détermination robuste: utilisateur connecté ?
$isAuth = false;
try {
    if (class_exists(\App\Util::class) && is_callable([\App\Util::class, 'currentUserId'])) {
        $uid = (int)call_user_func([\App\Util::class, 'currentUserId']);
        $isAuth = $uid > 0;
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        foreach ([
            $_SESSION['user']['id'] ?? null,
            $_SESSION['user_id'] ?? null,
            $_SESSION['id'] ?? null,
        ] as $cid) { if (is_numeric($cid) && (int)$cid > 0) { $isAuth = true; break; } }
    }
} catch (\Throwable $e) { /* ignore */ }
?>
<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom mb-3">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php" title="Accueil">
      <?= $brandHtml ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Menu">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link<?= nav_active('accounts.php') ?>" href="accounts.php">Comptes</a></li>
        <li class="nav-item"><a class="nav-link<?= nav_active('index.php') ?>" href="index.php">Transactions</a></li>
        <?php if (is_file(__DIR__ . '/micro_index.php')): ?>
          <li class="nav-item"><a class="nav-link<?= nav_active('micro_index.php') ?>" href="micro_index.php">Cotisations</a></li>
        <?php endif; ?>
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
        <?php if ($isAuth && $navUserEmail !== ''): ?>
          <span class="navbar-text small text-muted d-none d-md-inline"><?= h($navUserEmail) ?></span>
        <?php endif; ?>

<<<<<<< HEAD
        <?php if (is_file(__DIR__ . '/faq.php')): ?>
          <a class="btn btn-sm btn-outline-primary" href="faq.php">FAQ</a>
        <?php endif; ?>

=======
>>>>>>> 263d81e7e4119d765b3bab7153092f13ec10860c
        <?php if (is_file(__DIR__ . '/aide.php')): ?>
          <a class="btn btn-sm btn-outline-primary" href="aide.php">Aide</a>
        <?php endif; ?>

        <?php if ($isAuth && is_file(__DIR__ . '/logout.php')): ?>
          <a class="btn btn-sm btn-outline-secondary" href="logout.php">Déconnexion</a>
        <?php elseif (is_file(__DIR__ . '/login.php')): ?>
          <a class="btn btn-sm btn-primary" href="login.php">Connexion</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>