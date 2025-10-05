<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\{Util,Database,UserRepository};

Util::startSession();
if (Util::currentUserId()) {
    Util::redirect('index.php');
}
$db = new Database();
$db->ensureSchema(__DIR__.'/../schema.sql');
$users = new UserRepository($db);

$error = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        Util::checkCsrf();
        $email = trim($_POST['email'] ?? '');
        $pwd   = $_POST['password'] ?? '';
        if ($email==='' || $pwd==='') throw new RuntimeException('Champs requis.');
        $uid = $users->verifyLogin($email,$pwd);
        if(!$uid) throw new RuntimeException('Identifiants invalides.');
        Util::regenerateSessionId();
        $_SESSION['user_id']=$uid;
        Util::addFlash('success','Connexion OK');
        Util::redirect('index.php');
    } catch(Throwable $e){
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Connexion</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<h1 class="h4 mb-3">Connexion</h1>
<?php foreach(App\Util::takeFlashes() as $fl): ?>
  <div class="alert alert-<?= App\Util::h($fl['type']) ?>"><?= App\Util::h($fl['msg']) ?></div>
<?php endforeach; ?>
<?php if($error): ?><div class="alert alert-danger"><?= App\Util::h($error) ?></div><?php endif; ?>
<form method="post" class="card p-3">
  <?= App\Util::csrfInput() ?>
  <div class="mb-3">
    <label class="form-label">Email</label>
    <input name="email" type="email" class="form-control" required value="<?= App\Util::h($_POST['email'] ?? '') ?>">
  </div>
  <div class="mb-3">
    <label class="form-label">Mot de passe</label>
    <input name="password" type="password" class="form-control" required>
  </div>
  <button class="btn btn-primary w-100">Se connecter</button>
</form>
<p class="mt-3"><a href="register.php">Créer un compte</a></p>
</body>
</html>