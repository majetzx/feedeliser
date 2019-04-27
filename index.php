<?php
// Feedeliser - Main script

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use majetzx\feedeliser\Feedeliser;

require_once 'vendor/autoload.php';
require_once 'src/Feed.php';
require_once 'src/Feedeliser.php';

ini_set('display_errors', 0);

$log = new Logger('feedeliser');
$log->pushHandler(new StreamHandler('datas/log', Logger::DEBUG));

try {
    new Feedeliser($log);
} catch (\Throwable $th) {
    $log->error("Exception: {$th->getMessage()}", [
        'exception' => $th,
    ]);
}
