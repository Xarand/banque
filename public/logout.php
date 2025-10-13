<?php declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/bootstrap.php';

use App\{Util, Database};
use App\Security\AuthService;

Util::startSession();

if (Util::isLogged()) {
    $db   = new Database();
    $auth = new AuthService($db->pdo());
    $auth->clearUserTokens(Util::currentUserId());
}

if (!empty($_COOKIE['REMEMBER_ME'])) {
    setcookie('REMEMBER_ME','', time()-3600,'/', '', false, true);
}

Util::logoutUser();
Util::redirect('login.php');