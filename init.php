<?php
declare(strict_types=1);

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// set up monolog
$log          = new Logger('sync');
$stringFormat = "[%datetime%] %level_name%: %message% %context% %extra%\n";
$dateFormat   = 'H:i:s';
$formatter    = new LineFormatter($stringFormat, $dateFormat, true, true);
$handler      = new StreamHandler('php://stdout', $_ENV['LOG_LEVEL']);
$handler->setFormatter($formatter);
$log->pushHandler($handler);
