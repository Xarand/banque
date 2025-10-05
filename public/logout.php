<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use App\Util;

Util::startSession();
$_SESSION = [];
session_destroy();
Util::startSession();
Util::addFlash('success','Déconnecté');
Util::redirect('login.php');