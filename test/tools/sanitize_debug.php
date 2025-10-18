<?php
declare(strict_types=1);

// Nettoie ini_set('display_errors', '1'/'On'), ini_set('display_startup_errors', '1'/'On'),
// et les error_reporting(E_ALL) isolés. Crée un .bak avant modif.
// Lancez-le une fois puis SUPPRIMEZ-LE.

ini_set('display_errors','1'); error_reporting(E_ALL);

$root = dirname(__DIR__);
$skip = ['vendor','node_modules','data','assets','logs','.git','tools'];

$patterns = [
  '~^\s*@?ini_set\(\s*[\'"]display_errors[\'"]\s*,\s*[\'"](1|On)[\'"]\s*\)\s*;\s*$~mi' => '',
  '~^\s*@?ini_set\(\s*[\'"]display_startup_errors[\'"]\s*,\s*[\'"](1|On)[\'"]\s*\)\s*;\s*$~mi' => '',
  '~^\s*error_reporting\(\s*E_ALL\s*\)\s*;\s*$~mi' => '',
];

$it = new RecursiveIteratorIterator(
  new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    fn($cur,$k,$it) => !($it->hasChildren() && in_array($cur->getFilename(), $skip, true))
  ),
  RecursiveIteratorIterator::SELF_FIRST
);

$changed = [];
foreach ($it as $f) {
  if (!$f->isFile() || strtolower($f->getExtension())!=='php') continue;
  $p = $f->getPathname();
  $src = file_get_contents($p);
  $out = $src;
  foreach ($patterns as $rx=>$rep) $out = preg_replace($rx, $rep, $out);
  if ($out !== $src) { @copy($p, $p.'.bak'); file_put_contents($p, $out); $changed[]=$p; }
}

header('Content-Type: text/plain; charset=UTF-8');
echo "Nettoyage terminé. Fichiers modifiés: ".count($changed)."\n";
foreach ($changed as $c) echo "- $c\n";