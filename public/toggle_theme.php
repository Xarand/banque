<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
use App\{Util, Database};

Util::startSession();
Util::requireAuth();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = Util::currentUserId();

// Assure la table/colonne pour mémoriser le mode
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_settings (
  user_id INTEGER PRIMARY KEY,
  navbar_bg TEXT, navbar_text TEXT,
  body_bg TEXT, body_text TEXT,
  table_header_bg TEXT, table_header_text TEXT,
  link_color TEXT
);
");
$hasCol = function(string $col) use ($pdo): bool {
    $st = $pdo->query("PRAGMA table_info(user_settings)");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (strcasecmp((string)$c['name'], $col) === 0) return true;
    }
    return false;
};
if (!$hasCol('theme_mode')) {
    $pdo->exec("ALTER TABLE user_settings ADD COLUMN theme_mode TEXT");
}

// Bascule
$current = $_SESSION['theme_mode'] ?? null;
$new = ($current === 'dark') ? 'light' : 'dark';

// Persiste par utilisateur (UPSERT)
$st = $pdo->prepare("
  INSERT INTO user_settings (user_id, theme_mode)
  VALUES (:u, :m)
  ON CONFLICT(user_id) DO UPDATE SET theme_mode = :m
");
$st->execute([':u'=>$userId, ':m'=>$new]);

// Mémorise en session pour effet immédiat
$_SESSION['theme_mode'] = $new;

// Redirige vers la page d’origine si locale, sinon vers index
$redir = 'index.php';
if (!empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    // Sécurité simple: accepte les chemins locaux
    if (strpos($ref, 'http://') === 0 || strpos($ref, 'https://') === 0) {
        $url = parse_url($ref);
        if (!empty($url['path'])) {
            $path = $url['path'];
            if (str_ends_with($path, '.php')) {
                $redir = basename($path);
                if (!empty($url['query'])) $redir .= '?'.$url['query'];
            }
        }
    } else {
        // Chemin relatif
        $redir = $ref;
    }
}
header('Location: '.$redir);
exit;