<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database};

ini_set('display_errors', '1'); // à couper en prod si besoin
error_reporting(E_ALL);

Util::startSession();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Si déjà connecté, on renvoie vers le tableau
if (Util::currentUserId()) {
    Util::redirect('index.php');
    exit;
}

// Outils schéma dynamiques
function tableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
    $st->execute([':t' => $table]);
    return (bool) $st->fetchColumn();
}
function columns(PDO $pdo, string $table): array {
    $st = $pdo->prepare("PRAGMA table_info($table)");
    $st->execute();
    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[strtolower((string)$c['name'])] = true;
    }
    return $cols;
}

// Crée une table users minimale si elle n’existe pas (SQLite)
// id, username, password_hash, created_at
if (!tableExists($pdo, 'users')) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            created_at TEXT
        );
        CREATE UNIQUE INDEX IF NOT EXISTS ux_users_username ON users(username);
    ");
}

// Détecter les colonnes disponibles
$cols = columns($pdo, 'users');

// Choix de la colonne de login (username > email > login)
$loginCol = 'username';
if (!isset($cols[$loginCol])) {
    if (isset($cols['email']))        $loginCol = 'email';
    elseif (isset($cols['login']))    $loginCol = 'login';
    else                               $loginCol = 'username'; // fallback (créée ci-dessus si table auto-créée)
}

// Choix de la colonne password
$pwdCol = 'password_hash';
if (!isset($cols[$pwdCol])) {
    if (isset($cols['password'])) $pwdCol = 'password';
    else                          $pwdCol = 'password_hash'; // fallback
}

// POST: création de compte
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();

        $login = trim((string)($_POST['login'] ?? ''));
        $pass1 = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password_confirm'] ?? '');

        if ($login === '')   throw new RuntimeException("Identifiant requis.");
        if ($pass1 === '')   throw new RuntimeException("Mot de passe requis.");
        if ($pass1 !== $pass2) throw new RuntimeException("La confirmation ne correspond pas.");

        // Existence ?
        $sqlExists = "SELECT id FROM users WHERE $loginCol = :v LIMIT 1";
        $st = $pdo->prepare($sqlExists);
        $st->execute([':v' => $login]);
        if ($st->fetchColumn()) {
            throw new RuntimeException("Cet identifiant existe déjà.");
        }

        // Hachage (si la colonne s’appelle password, on stocke quand même un hash)
        $hash = password_hash($pass1, PASSWORD_DEFAULT);

        // Construire l’INSERT dynamiquement
        $colsToInsert = [$loginCol, $pwdCol];
        $vals         = [':login', ':pwd'];
        $bind         = [':login' => $login, ':pwd' => $hash];

        if (isset($cols['created_at'])) {
            $colsToInsert[] = 'created_at';
            $vals[]         = "datetime('now')";
        }

        $sql = "INSERT INTO users (".implode(',', $colsToInsert).") VALUES (".implode(',', $vals).")";
        $pdo->prepare($sql)->execute($bind);

        // Récupérer l’id et connecter
        $userId = (int)$pdo->lastInsertId();
        $_SESSION['user_id'] = $userId;

        Util::addFlash('success', "Compte créé et connecté.");
        Util::redirect('index.php');
    } catch (Throwable $e) {
        Util::addFlash('danger', $e->getMessage());
        Util::redirect('register.php');
    }
    exit;
}

function h(string $s): string { return App\Util::h($s); }
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Créer un compte</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__.'/_nav.php'; ?>

<div class="container py-4" style="max-width: 680px;">
  <?php foreach (App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?>"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="card shadow-sm">
    <div class="card-header py-2"><strong>Créer un compte</strong></div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <?= App\Util::csrfInput() ?>
        <div class="col-12">
          <label class="form-label">Identifiant</label>
          <input type="text" name="login" class="form-control" required placeholder="Votre identifiant">
          <div class="form-text">Selon le schéma: <?= h($loginCol) ?>.</div>
        </div>
        <div class="col-sm-6">
          <label class="form-label">Mot de passe</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <div class="col-sm-6">
          <label class="form-label">Confirmation</label>
          <input type="password" name="password_confirm" class="form-control" required>
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary">Créer mon compte</button>
          <a class="btn btn-outline-secondary" href="login.php">J’ai déjà un compte</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>