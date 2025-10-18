<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/bootstrap.php';

use App\{Util, Database};

Util::startSession();

$pdo = (new Database())->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    Util::addFlash('danger', 'Token manquant.');
    header('Location: login.php'); exit;
}

try {
    // recherche du user par token
    $st = $pdo->prepare("SELECT id, email, department, activity, created_at FROM users WHERE verify_token = :t LIMIT 1");
    $st->execute([':t'=>$token]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        Util::addFlash('danger', 'Token invalide.');
        header('Location: login.php'); exit;
    }

    $userId = (int)$u['id'];
    // marque comme vérifié et supprime le token
    $upd = $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL WHERE id = :id");
    $upd->execute([':id'=>$userId]);

    // Ajoute en CSV (ou vers Google Sheets si configuré)
    require_once __DIR__.'/_registration_helpers.php';
    $email = (string)$u['email'];
    $department = (string)($u['department'] ?? '');
    $activity = (string)($u['activity'] ?? '');
    $createdAt = (string)($u['created_at'] ?? date('Y-m-d H:i:s'));
    appendRegistrationCsv($email, $department, $activity, $createdAt);

    Util::addFlash('success', 'Email confirmé. Vous pouvez vous connecter.');
    header('Location: login.php');
    exit;

} catch (Throwable $e) {
    Util::addFlash('danger', 'Erreur: ' . $e->getMessage());
    header('Location: login.php');
    exit;
}