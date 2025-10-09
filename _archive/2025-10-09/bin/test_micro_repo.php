<?php
require __DIR__.'/../vendor/autoload.php';
use App\Database;
use App\MicroEnterpriseRepository;

$db = new Database();
$repo = new MicroEnterpriseRepository($db->pdo());
echo "OK: ".get_class($repo).PHP_EOL;