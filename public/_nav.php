<?php
// Détermine la page courante pour marquer l’onglet actif
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');

function navActive(array $files): string {
    global $current;
    return in_array($current, $files, true) ? ' active' : '';
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3">
  <div class="container">
    <a class="navbar-brand" href="index.php">Banque</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topnav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="topnav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item">
          <a class="nav-link<?= navActive(['index.php']) ?>" href="index.php">Tableau</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= navActive(['reports.php']) ?>" href="reports.php">Rapports</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= navActive(['accounts.php']) ?>" href="accounts.php">Comptes</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= navActive(['micro_index.php','micro_view.php']) ?>" href="micro_index.php">Micro</a>
        </li>
      </ul>
      <div class="d-flex">
        <a class="btn btn-outline-light btn-sm" href="logout.php">Déconnexion</a>
      </div>
    </div>
  </div>
</nav>