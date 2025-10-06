<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\{Util, Database, UserRepository};
use RuntimeException;

Util::startSession();

// Redirige si déjà authentifié
if (Util::isAuthenticated()) {
    Util::redirect('index.php');
}

$db       = new Database();          // plus de ensureSchema()
$users    = new UserRepository($db);
$error    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Util::checkCsrf();

        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            throw new RuntimeException("Email et mot de passe requis.");
        }

        $user = $users->findByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new RuntimeException("Identifiants invalides.");
        }

        Util::loginUserId((int)$user['id']);
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
<title>Connexion</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:480px;">
  <h1 class="h4 mb-4 text-center">Connexion</h1>

  <?php if ($error): ?>
    <div class="alert alert-danger py-2"><?= App\Util::h($error) ?></div>
  <?php endif; ?>

  <?php foreach (App\Util::takeFlashes() as $fl): ?>
    <div class="alert alert-<?= App\Util::h($fl['type']) ?> py-2"><?= App\Util::h($fl['msg']) ?></div>
  <?php endforeach; ?>

  <form method="post" class="card shadow-sm p-3">
    <?= App\Util::csrfInput() ?>
    <div class="mb-3">
      <label class="form-label mb-1">Email</label>
      <input type="email" name="email" class="form-control" required value="<?= App\Util::h($_POST['email'] ?? '') ?>">
    </div>
    <div class="mb-3">
      <label class="form-label mb-1">Mot de passe</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button class="btn btn-primary w-100">Se connecter</button>
  </form>

  <p class="mt-3 text-center">
    <a href="register.php">Créer un compte</a>
  </p>
</div>
</body>
</html>