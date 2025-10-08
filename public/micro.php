<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database, MicroEnterpriseRepository};

Util::startSession();
Util::requireAuth();

$repo = new MicroEnterpriseRepository((new App\Database())->pdo());
$repo->ensureSingleForUser(Util::currentUserId());
$micro = $repo->getOrCreateSingle(Util::currentUserId());

Util::redirect('micro_view.php?id='.$micro['id']);