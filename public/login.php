<?php declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/bootstrap.php';

use App\{Util, Database};
use App\Security\AuthService;

Util::startSession();

if (Util::isLogged()) {
    Util::redirect('index.php');
}

$db   = new Database();
$pdo  = $db->pdo();
$auth = new AuthService($pdo);

// Tentative auto via remember me
if (!Util::isLogged() && !empty($_COOKIE['REMEMBER_ME'])) {
    $userId = $auth->consumeRememberMeToken($_COOKIE['REMEMBER_ME']);
    if ($userId) {
        Util::loginUser($userId, true);
        Util::redirect('index.php');
    } else {
        setcookie('REMEMBER_ME','', time()-3600,'/', '', false, true);
    }
}

$error = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
    Util::checkCsrf();
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    if ($auth->isTemporarilyBlocked($email, $ip)) {
        $error = "Trop de tentatives. Réessaie dans quelques minutes.";
    } else {
        $st = $pdo->prepare("SELECT id,password_hash FROM users WHERE email=:e LIMIT 1");
        $st->execute([':e'=>$email]);
        $user = $st->fetch(PDO::FETCH_ASSOC);

        $ok = false;
        $uid = null;
        if ($user && password_verify($password, $user['password_hash'])) {
            $ok  = true;
            $uid = (int)$user['id'];
        }
        $auth->logAttempt($uid, $email, $ip, $ua, $ok);

        if ($ok) {
            Util::loginUser($uid, true);
            if ($remember) {
                $token = $auth->createRememberMeToken($uid);
                setcookie(
                    'REMEMBER_ME',
                    $token,
                    [
                        'expires'  => time()+60*60*24*30,
                        'path'     => '/',
                        'secure'   => false, // mettre true en HTTPS
                        'httponly' => true,
                        'samesite' => 'Strict'
                    ]
                );
            }
            Util::redirect('index.php');
        } else {
            $error = "Identifiants invalides.";
        }
    }
}

function h($v){ return App\Util::h((string)$v); }
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Connexion</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-4">
      <h1 class="h5 mb-3 text-center">Connexion</h1>
      <?php if($error): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
      <?php endif; ?>
      <form method="post" class="card p-3 shadow-sm">
        <?= App\Util::csrfInput() ?>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input name="email" type="email" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label">Mot de passe</label>
          <input name="password" type="password" class="form-control" required>
        </div>
        <div class="mb-3 form-check">
          <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
          <label class="form-check-label" for="remember">Se souvenir de moi</label>
        </div>
        <button class="btn btn-primary w-100">Se connecter</button>
        <div class="mt-3 text-center">
          <a href="register.php">Créer un compte</a>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>