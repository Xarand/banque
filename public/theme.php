<?php
declare(strict_types=1);

// Feuille de style thématique générée dynamiquement.
// Chargée via _head_assets.php, prend ses valeurs dans le cookie theme_vars ou retombe sur $defaults.

header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');

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

// Construit les variables à partir du cookie
$vars = $defaults;
if (!empty($_COOKIE['theme_vars'])) {
    $j = json_decode((string)$_COOKIE['theme_vars'], true);
    if (is_array($j)) {
        foreach ($j as $k=>$v) {
            if (isset($defaults[$k]) && is_string($v) && preg_match('~^#([0-9a-f]{3}|[0-9a-f]{6})$~i', $v)) {
                $vars[$k] = strtoupper($v);
            }
        }
    }
}

function cssVars(array $vars): string {
    $out = ":root{\n";
    foreach ($vars as $k=>$v) $out .= "  $k: $v;\n";
    $out .= "}\n";
    return $out;
}

echo "/* Theme CSS generated ".gmdate('c')." */\n";
echo cssVars($vars);

// Habillage Bootstrap minimal basé sur variables
?>
body{background-color:var(--app-bg);color:var(--app-fg)}
.border,.card,.table,.form-control,.form-select{border-color:var(--app-border)!important}
.card{background-color:var(--card-bg)!important;color:var(--app-fg)!important}
.card-header{background-color:var(--card-header-bg)!important;color:var(--card-header-text)!important;border-bottom-color:var(--app-border)!important}
.table thead,.table-light{background-color:var(--thead-bg)!important;color:var(--app-fg)!important}
a, .link-primary{color:var(--primary)!important}
.btn-primary{--bs-btn-bg:var(--primary);--bs-btn-border-color:var(--primary)}