<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\{Util, Database, UserRepository};
use RuntimeException;

Util::startSession();

// Si déjà connecté, inutile d'afficher l'inscription
if (Util::isAuthenticated()) {
    Util::redirect('index.php');
}

$db     = new Database();          // plus de ensureSchema()
$users  = new UserRepository($db);
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();

        $email       = strtolower(trim($_POST['email'] ?? ''));
        $displayName = trim($_POST['display_name'] ?? '');
        $password    = $_POST['password'] ?? '';

        if ($email === '' || $displayName === '' || $password === '') {
            throw new RuntimeException("Tous les champs sont requis.");
        }
        if (strlen($password) < 8) {
            throw new RuntimeException("Mot de passe trop court (min 8).");
        }

        $userId = $users->create($email, $displayName, $password);
        Util::loginUserId($userId);
        Util::redirect('index.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Inscription</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:520px;">
  <h1 class="h4 mb-4 text-center">Créer un compte</h1>

  <?php if ($error): ?>
    <div class="alert alert-danger py-2"><?= App\Util::h($error) ?></div>
  <?php endif; ?>

  <form method="post" class="card shadow-sm p-3">
    <?= App\Util::csrfInput() ?>
    <div class="mb-3">
      <label class="form-label mb-1">Email</label>
      <input type="email" name="email" class="form-control" required value="<?= App\Util::h($_POST['email'] ?? '') ?>">
    </div>
    <div class="mb-3">
      <label class="form-label mb-1">Nom affiché</label>
      <input name="display_name" class="form-control" required value="<?= App\Util::h($_POST['display_name'] ?? '') ?>">
    </div>
    <div class="mb-3">
      <label class="form-label mb-1">Mot de passe (min 8)</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button class="btn btn-success w-100">S'inscrire</button>
  </form>

  <p class="mt-3 text-center">
    <a href="login.php">Déjà inscrit ? Connexion</a>
  </p>
</div>
</body>
</html>