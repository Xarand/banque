<?php
declare(strict_types=1);

// Pas d’autoload strictement nécessaire ici, mais on garde la nav et l'auth si disponibles
if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
    if (class_exists(\App\Util::class)) {
        try { \App\Util::startSession(); \App\Util::requireAuth(); } catch (\Throwable $e) {}
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
}

// Valeurs actuelles depuis le cookie
$vars = [];
if (!empty($_COOKIE['theme_vars'])) {
    $tmp = json_decode($_COOKIE['theme_vars'], true);
    if (is_array($tmp)) $vars = $tmp;
}
function val(string $k, string $fallback): string {
    global $vars;
    return htmlspecialchars((string)($vars[$k] ?? $fallback), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

include __DIR__.'/_nav.php';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Thème — Couleurs</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-3">
  <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success py-2 mb-3">
      Réglages enregistrés<?= isset($_GET['reset']) ? ' (réinitialisation effectuée)' : '' ?>.
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <strong>Couleurs du thème</strong>
      <a class="btn btn-sm btn-outline-secondary" href="toggle_theme.php">
        Basculer clair/sombre
      </a>
    </div>
    <div class="card-body">
      <form method="post" action="save_theme.php" class="row g-3">

        <div class="col-md-3">
          <label class="form-label">Couleur principale (accent)</label>
          <input type="color" class="form-control form-control-color" name="primary" value="<?= val('primary','#0D6EFD') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Fond de page (app_bg)</label>
          <input type="color" class="form-control form-control-color" name="app_bg" value="<?= val('app_bg','#F5F6F8') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Fond des cartes (card_bg)</label>
          <input type="color" class="form-control form-control-color" name="card_bg" value="<?= val('card_bg','#FFFFFF') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Fond de la barre (nav_bg)</label>
          <input type="color" class="form-control form-control-color" name="nav_bg" value="<?= val('nav_bg','#F8F9FA') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Texte principal (fg)</label>
          <input type="color" class="form-control form-control-color" name="fg" value="<?= val('fg','#212529') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Texte atténué (muted)</label>
          <input type="color" class="form-control form-control-color" name="muted" value="<?= val('muted','#6C757D') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Bordures (border)</label>
          <input type="color" class="form-control form-control-color" name="border" value="<?= val('border','#DEE2E6') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">En-têtes de tableaux (thead_bg)</label>
          <input type="color" class="form-control form-control-color" name="thead_bg" value="<?= val('thead_bg','#F1F3F5') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Fond champs (input_bg)</label>
          <input type="color" class="form-control form-control-color" name="input_bg" value="<?= val('input_bg','#FFFFFF') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Texte champs (input_fg)</label>
          <input type="color" class="form-control form-control-color" name="input_fg" value="<?= val('input_fg','#212529') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Bordure champs (input_border)</label>
          <input type="color" class="form-control form-control-color" name="input_border" value="<?= val('input_border','#CED4DA') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Survol lignes tableau</label>
          <input type="color" class="form-control form-control-color" name="table_row_hover" value="<?= val('table_row_hover','#F8F9FA') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Liens navbar (nav_link) — optionnel</label>
          <input type="color" class="form-control form-control-color" name="nav_link" value="<?= val('nav_link','#495057') ?>">
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary">Enregistrer</button>
          <button class="btn btn-outline-danger" name="reset" value="1" type="submit">Réinitialiser</button>
        </div>

        <div class="form-text">
          Astuce: après modification, si le navigateur ne recharge pas le CSS, faites Ctrl+F5.
        </div>
      </form>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>