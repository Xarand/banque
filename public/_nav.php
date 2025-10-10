<?php
// Charge le thème dynamique
echo '<link rel="stylesheet" href="theme.php">';

// Page courante pour l’onglet actif
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
function navActive(array $files): string {
    global $current;
    return in_array($current, $files, true) ? ' active' : '';
}

// État du mode (issu de la session mise à jour par toggle_theme.php)
$mode = $_SESSION['theme_mode'] ?? 'light';
$toggleLabel = $mode === 'dark' ? 'Clair' : 'Sombre';
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3">
  <div class="container">
    <a class="navbar-brand" href="index.php">Banque</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topnav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="topnav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link<?= navActive(['index.php']) ?>" href="index.php">Tableau</a></li>
        <li class="nav-item"><a class="nav-link<?= navActive(['reports.php']) ?>" href="reports.php">Rapports</a></li>
        <li class="nav-item"><a class="nav-link<?= navActive(['accounts.php']) ?>" href="accounts.php">Comptes</a></li>
        <li class="nav-item"><a class="nav-link<?= navActive(['micro_index.php','micro_view.php']) ?>" href="micro_index.php">Micro</a></li>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <a class="btn btn-outline-light btn-sm<?= navActive(['settings.php']) ?>" href="settings.php">Réglage</a>
        <a class="btn btn-outline-light btn-sm" href="toggle_theme.php" title="Basculer le thème"><?= htmlspecialchars($toggleLabel) ?></a>
        <a class="btn btn-outline-light btn-sm" href="logout.php">Déconnexion</a>
      </div>
    </div>
  </div>
</nav>