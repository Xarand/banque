<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/bootstrap.php';

use App\{Util, Database};

Util::startSession();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Si déjà connecté, on renvoie vers le tableau
if (Util::currentUserId()) {
    Util::redirect('index.php');
    exit;
}

/* -----------------------
   Helpers schéma / util
   ----------------------- */
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

/**
 * Ajoute des colonnes utiles à l'inscription si elles manquent.
 * (Exécuter ici en local est pratique; en production préférez script de migration administratif.)
 */
function ensureRegistrationColumns(PDO $pdo): void {
    $cols = columns($pdo, 'users');
    $toAdd = [];
    if (!isset($cols['department']))       $toAdd[] = "ALTER TABLE users ADD COLUMN department TEXT NULL";
    if (!isset($cols['activity']))         $toAdd[] = "ALTER TABLE users ADD COLUMN activity TEXT NULL";
    if (!isset($cols['cma_rate']))         $toAdd[] = "ALTER TABLE users ADD COLUMN cma_rate REAL NULL";
    if (!isset($cols['email_verified']))   $toAdd[] = "ALTER TABLE users ADD COLUMN email_verified INTEGER NOT NULL DEFAULT 0";
    if (!isset($cols['verify_token']))     $toAdd[] = "ALTER TABLE users ADD COLUMN verify_token TEXT NULL";
    if (!isset($cols['consent_at']))       $toAdd[] = "ALTER TABLE users ADD COLUMN consent_at TEXT NULL";

    foreach ($toAdd as $sql) {
        try { $pdo->exec($sql); } catch (Throwable) { /* ignore - possible sqlite limitations */ }
    }
}

/**
 * Détermine le taux CMA en fonction du département et de l'activité.
 * Renvoie un float (ex: 0.0065 pour 0.650%).
 */
function determineCmaRate(string $department, string $activity): float {
    $dep = strtolower(trim($department));
    $act = strtolower(trim($activity));

    // Normalisation simple pour Alsace (67/68/67?) et Moselle (57)
    // Vous pouvez étendre/préciser selon codes INSEE ou noms exacts.
    $map = [
        // activity => [depart => rate]
        'services' => [
            'alsace'  => 0.00650, // 0.650%
            'moselle' => 0.00830, // 0.830%
        ],
    ];

    if (isset($map[$act]) && isset($map[$act][$dep])) return (float)$map[$act][$dep];
    return 0.0;
}

/* -----------------------
   Préparation du schéma
   ----------------------- */
if (!tableExists($pdo, 'users')) {
    // Crée une table users minimale si elle n’existe pas (old behaviour)
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

// s'assurer des nouvelles colonnes (safe: tente d'ajouter si manquantes)
ensureRegistrationColumns($pdo);

// Re-détecter les colonnes
$cols = columns($pdo, 'users');

// Choix de la colonne de login (username > email > login)
$loginCol = 'username';
if (!isset($cols[$loginCol])) {
    if (isset($cols['email']))        $loginCol = 'email';
    elseif (isset($cols['login']))    $loginCol = 'login';
    else                               $loginCol = 'username';
}

// Choix de la colonne password
$pwdCol = 'password_hash';
if (!isset($cols[$pwdCol])) {
    if (isset($cols['password'])) $pwdCol = 'password';
    else                          $pwdCol = 'password_hash';
}

/* -----------------------
   POST: création de compte
   ----------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();

        $login = trim((string)($_POST['login'] ?? ''));
        $pass1 = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password_confirm'] ?? '');

        // Nouveaux champs
        $department = trim((string)($_POST['department'] ?? ''));
        $activity   = trim((string)($_POST['activity'] ?? ''));
        $acceptPrivacy = isset($_POST['accept_privacy']) && $_POST['accept_privacy'] === '1';

        if ($login === '')   throw new RuntimeException("Identifiant requis.");
        if ($pass1 === '')   throw new RuntimeException("Mot de passe requis.");
        if ($pass1 !== $pass2) throw new RuntimeException("La confirmation ne correspond pas.");
        if (!$acceptPrivacy) throw new RuntimeException("Vous devez accepter la politique de confidentialité.");

        // Existence ?
        $sqlExists = "SELECT id FROM users WHERE $loginCol = :v LIMIT 1";
        $st = $pdo->prepare($sqlExists);
        $st->execute([':v' => $login]);
        if ($st->fetchColumn()) {
            throw new RuntimeException("Cet identifiant existe déjà.");
        }

        // Hachage
        $hash = password_hash($pass1, PASSWORD_DEFAULT);

        // Générer token de confirmation
        $token = bin2hex(random_bytes(24));
        $createdAt = date('Y-m-d H:i:s');
        $consentAt = $createdAt;
        $cmaRate = determineCmaRate($department, $activity);

        // Construire INSERT dynamiquement selon colonnes disponibles
        $colsToInsert = [$loginCol, $pwdCol];
        $vals = [':login', ':pwd'];
        $bind = [':login' => $login, ':pwd' => $hash];

        if (isset($cols['created_at'])) {
            $colsToInsert[] = 'created_at';
            $vals[] = "datetime('now')";
        }

        if (isset($cols['department'])) {
            $colsToInsert[] = 'department';
            $vals[] = ':department';
            $bind[':department'] = $department ?: null;
        }
        if (isset($cols['activity'])) {
            $colsToInsert[] = 'activity';
            $vals[] = ':activity';
            $bind[':activity'] = $activity ?: null;
        }
        if (isset($cols['cma_rate'])) {
            $colsToInsert[] = 'cma_rate';
            $vals[] = ':cma_rate';
            $bind[':cma_rate'] = $cmaRate ?: null;
        }
        if (isset($cols['email_verified'])) {
            $colsToInsert[] = 'email_verified';
            $vals[] = ':email_verified';
            $bind[':email_verified'] = 0;
        }
        if (isset($cols['verify_token'])) {
            $colsToInsert[] = 'verify_token';
            $vals[] = ':verify_token';
            $bind[':verify_token'] = $token;
        }
        if (isset($cols['consent_at'])) {
            $colsToInsert[] = 'consent_at';
            $vals[] = ':consent_at';
            $bind[':consent_at'] = $consentAt;
        }

        $sql = "INSERT INTO users (" . implode(',', $colsToInsert) . ") VALUES (" . implode(',', $vals) . ")";
        $pdo->prepare($sql)->execute($bind);

        // Ne pas auto-login: envoyer email de confirmation
        // Construire URL de vérification
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
        $verifyUrl = $proto . '://' . $host . $base . '/verify_email.php?token=' . urlencode($token);

        $subject = "Confirmez votre adresse e-mail";
        $message = "Bonjour,\n\nMerci de confirmer votre adresse e-mail en cliquant sur le lien suivant :\n\n{$verifyUrl}\n\nSi vous n'avez pas créé de compte, ignorez ce message.\n";
        $headers = "From: no-reply@" . ($host) . "\r\n";

        // Tentative d'envoi (en prod, utilisez SMTP/PHPMailer)
        @mail($login, $subject, $message, $headers);

        Util::addFlash('info', "Un e‑mail de confirmation a été envoyé à {$login}. Veuillez valider votre adresse avant de vous connecter.");
        Util::redirect('login.php');
        exit;

    } catch (Throwable $e) {
        Util::addFlash('danger', $e->getMessage());
        Util::redirect('register.php');
    }
    exit;
}

/* -----------------------
   Formulaire HTML
   ----------------------- */
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

<div class="container py-4" style="max-width: 720px;">
  <?php foreach (App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= h($fl['type']) ?>"><?= h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <div class="card shadow-sm">
    <div class="card-header py-2"><strong>Créer un compte</strong></div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <?= App\Util::csrfInput() ?>

        <div class="col-12">
          <label class="form-label">Adresse e‑mail</label>
          <input type="email" name="login" class="form-control" required placeholder="Votre adresse e-mail">
          <div class="form-text">L'adresse e‑mail servira d'identifiant pour la confirmation.</div>
        </div>

        <div class="col-sm-6">
          <label class="form-label">Mot de passe</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <div class="col-sm-6">
          <label class="form-label">Confirmation</label>
          <input type="password" name="password_confirm" class="form-control" required>
        </div>

        <div class="col-md-6">
          <label class="form-label">Département</label>
          <select name="department" class="form-select" required>
            <option value="">Choisir…</option>
            <option value="Alsace">Alsace</option>
            <option value="Moselle">Moselle</option>
            <!-- Étendre la liste si besoin -->
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Activité</label>
          <select name="activity" class="form-select" required>
            <option value="">Choisir…</option>
            <option value="services">Prestation de services</option>
            <!-- autres activités possibles -->
          </select>
        </div>

        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="accept_privacy" name="accept_privacy" value="1" required>
            <label class="form-check-label" for="accept_privacy">
              J'accepte la <a href="privacy.php" target="_blank">politique de confidentialité</a> et j'autorise la collecte et le traitement des données indiquées.
            </label>
          </div>
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