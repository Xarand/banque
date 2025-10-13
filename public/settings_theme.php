<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/bootstrap.php';
use App\Util;

Util::startSession();
Util::requireAuth();

// Nouvelles valeurs par défaut (réinitialisation)
$defaults = [
  '--primary'          => '#0D1DFD',
  '--app-bg'           => '#AFDEE4',
  '--app-fg'           => '#0D1DFD',
  '--app-border'       => '#0D1DFD',
  '--card-bg'          => '#83BFCE',
  '--card-header-bg'   => '#71D1CF',
  '--card-header-text' => '#000000',
  '--thead-bg'         => '#83BFCE'
];

// Lire cookie existant
$current = $defaults;
if (!empty($_COOKIE['theme_vars'])) {
    $j = json_decode((string)$_COOKIE['theme_vars'], true);
    if (is_array($j)) {
        foreach ($j as $k=>$v) {
            if (isset($defaults[$k]) && is_string($v) && preg_match('~^#([0-9a-f]{3}|[0-9a-f]{6})$~i', $v)) {
                $current[$k] = strtoupper($v);
            }
        }
    }
}

function setThemeCookie(array $vars): void {
    $payload = json_encode($vars, JSON_UNESCAPED_SLASHES);
    setcookie('theme_vars', $payload, [
        'expires'  => time()+3600*24*365,
        'path'     => '/',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

// POST
if ($_SERVER['REQUEST_METHOD']==='POST') {
    Util::checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'reset') {
        setThemeCookie([]); // vide = retombe sur $defaults
        Util::addFlash('success', 'Thème réinitialisé aux couleurs par défaut.');
        Util::redirect('settings_theme.php'); exit;
    }
    if ($action === 'save') {
        $vars = [];
        foreach ($defaults as $k=>$def) {
            $v = (string)($_POST[$k] ?? $def);
            if (!preg_match('~^#([0-9a-f]{3}|[0-9a-f]{6})$~i', $v)) $v = $def;
            $vars[$k] = strtoupper($v);
        }
        setThemeCookie($vars);
        Util::addFlash('success','Thème enregistré.');
        Util::redirect('settings_theme.php'); exit;
    }
}

function h(string $s): string { return App\Util::h($s); }

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Thèmes</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__.'/_head_assets.php'; ?>
<style>
.preview-card .card-header{font-weight:600}
.color-cell{display:flex;align-items:center;gap:.5rem}
.color-cell input[type="color"]{width:2.25rem;height:2.25rem;padding:0;border:1px solid var(--app-border);border-radius:.25rem;background:#fff}
.var-name{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Courier New",monospace}
</style>
</head>
<body>
<?php include __DIR__.'/_nav.php'; ?>

<div class="container py-3">

  <?php foreach (App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?> py-2"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Palette</strong></div>
        <div class="card-body">
          <form method="post" id="themeForm" class="row g-3">
            <?= App\Util::csrfInput() ?>
            <input type="hidden" name="action" value="save">
            <?php
              $fields = [
                '--primary'          => 'Couleur primaire',
                '--app-bg'           => 'Fond application',
                '--app-fg'           => 'Texte principal',
                '--app-border'       => 'Bordures',
                '--card-bg'          => 'Fond cartes',
                '--card-header-bg'   => 'Entête cartes',
                '--card-header-text' => 'Texte entête cartes',
                '--thead-bg'         => 'Fond en-tête tableau'
              ];
              foreach ($fields as $var=>$label):
                $val = $current[$var] ?? $defaults[$var];
            ?>
            <div class="col-sm-6">
              <label class="form-label"><?= h($label) ?> <span class="text-muted var-name"><?= h($var) ?></span></label>
              <div class="color-cell">
                <input type="color" name="<?= h($var) ?>" id="<?= h(substr($var,2)) ?>" value="<?= h($val) ?>" data-var="<?= h($var) ?>">
                <input type="text" class="form-control form-control-sm" value="<?= h($val) ?>" data-mirror="<?= h($var) ?>" pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$">
              </div>
            </div>
            <?php endforeach; ?>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary">Enregistrer</button>
              <button class="btn btn-outline-secondary" name="action" value="reset" formaction="settings_theme.php">Réinitialiser</button>
            </div>
          </form>
          <div class="form-text mt-2">Les changements sont prévisualisés immédiatement et s’appliquent après enregistrement.</div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm preview-card">
        <div class="card-header py-2">Aperçu</div>
        <div class="card-body">
          <p>Texte principal avec <a href="#" class="link-primary">lien primaire</a>.</p>
          <div class="alert alert-light">Bloc “clair”</div>
          <table class="table table-sm">
            <thead class="table-light"><tr><th>En-tête</th><th>Valeur</th></tr></thead>
            <tbody>
              <tr><td>Bordure</td><td><code>--app-border</code></td></tr>
              <tr><td>Fond carte</td><td><code>--card-bg</code></td></tr>
            </tbody>
          </table>
          <button class="btn btn-primary btn-sm">Bouton primaire</button>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
(function(){
  // Live preview: update CSS variables on input
  function setVar(name, value){
    document.documentElement.style.setProperty(name, value);
  }
  // sync color <-> text inputs
  document.querySelectorAll('input[type="color"][data-var]').forEach(function(inp){
    const name = inp.dataset.var;
    inp.addEventListener('input', function(){
      setVar(name, inp.value);
      const txt = document.querySelector('input[data-mirror="'+name+'"]');
      if (txt) txt.value = inp.value.toUpperCase();
    });
  });
  document.querySelectorAll('input[type="text"][data-mirror]').forEach(function(inp){
    const name = inp.dataset.mirror;
    inp.addEventListener('input', function(){
      const v = inp.value.trim();
      if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v)) {
        setVar(name, v);
        const col = document.querySelector('input[type="color"][data-var="'+name+'"]');
        if (col) col.value = v;
      }
    });
  });
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>