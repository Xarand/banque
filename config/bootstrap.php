<?php
declare(strict_types=1);

// Force le mode production
if (!defined('APP_ENV')) {
    define('APP_ENV', 'prod');
}

error_reporting(E_ALL);

// En prod: ne rien afficher
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');

// Toujours journaliser
@ini_set('log_errors', '1');

// Journal dans data/logs/ si possible
$logDir  = dirname(__DIR__) . '/data/logs';
$logFile = $logDir . '/app-php-error.log';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
if (@is_dir($logDir) && @is_writable($logDir)) {
    @ini_set('error_log', $logFile);
}

// Fuseau horaire
@date_default_timezone_set('Europe/Paris');