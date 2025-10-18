<?php
declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../config/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "APP_ENV = ".(defined('APP_ENV')?APP_ENV:'(non défini)')."\n";
echo "display_errors = ".ini_get('display_errors')."\n";
echo "log_errors = ".ini_get('log_errors')."\n";
echo "error_log = ".ini_get('error_log')."\n";
echo "Timezone = ".date_default_timezone_get()."\n";

// Provoque un warning volontaire qui ne doit PAS s’afficher en prod
@trigger_error("Test warning bootstrap", E_USER_WARNING);

echo "OK.\n";