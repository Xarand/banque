<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\{Util,Database,UserRepository};

Util::startSession();

$db = new Database();
$db->ensureSchema(__DIR__.'/../schema.sql');
$users = new UserRepository($db);

$error = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        Util::checkCsrf();
        $email = trim($_POST['email'] ?? '');
        $display = trim($_POST['display_name'] ?? '');
        $pwd = $_POST['password'] ?? '';
        $uid = $users->create($email,$display,$pwd);
        Util::regenerateSessionId();
        $_SESSION['user_id']=$uid;
        Util::addFlash('success','Compte créé.');
        Util::redirect('index.php');
    } catch(Throwable $e){
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Inscription</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<h1 class="h4 mb-3">Créer un compte</h1>
<?php if($error): ?><div class="alert alert-danger"><?= App\Util::h($error) ?></div><?php endif; ?>
<form method="post" class="card p-3">
  <?= App\Util::csrfInput() ?>
  <div class="mb-3">
    <label class="form-label">Email</label>
    <input name="email" type="email" class="form-control" required value="<?= App\Util::h($_POST['email'] ?? '') ?>">
  </div>
  <div class="mb-3">
    <label class="form-label">Nom affiché</label>
    <input name="display_name" class="form-control" required value="<?= App\Util::h($_POST['display_name'] ?? '') ?>">
  </div>
  <div class="mb-3">
    <label class="form-label">Mot de passe (min 8)</label>
    <input name="password" type="password" class="form-control" required>
  </div>
  <button class="btn btn-primary w-100">Créer</button>
</form>
<p class="mt-3"><a href="login.php">Déjà inscrit ? Connexion</a></p>
</body>
</html>