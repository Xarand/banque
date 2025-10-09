<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\{Util, Database, MicroEnterpriseRepository};

ini_set('display_errors','1'); // désactiver en prod
error_reporting(E_ALL);

Util::startSession();
Util::requireAuth();

$pdo  = (new Database())->pdo();
$repo = new MicroEnterpriseRepository($pdo);
$userId = Util::currentUserId();

// Récupère (ou crée) l’unique micro de l’utilisateur et redirige vers sa vue
try {
    $micro = $repo->getOrCreateSingle($userId);
    if (!$micro || empty($micro['id'])) {
        Util::addFlash('danger', "Impossible d’ouvrir la micro.");
        header('Location: index.php', true, 302);
        exit;
    }
    header('Location: micro_view.php?id='.(int)$micro['id'], true, 302);
    exit;
} catch (Throwable $e) {
    Util::addFlash('danger', $e->getMessage());
    header('Location: index.php', true, 302);
    exit;
}