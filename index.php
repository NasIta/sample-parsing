<?php

use src\services\RepairLinkShop;

require(__DIR__ . '/vendor/autoload.php');
require(__DIR__ . '/config/main.php');
require(__DIR__ . '/config/main-local.php');

$config = require __DIR__ . '/config/main.php';

$vin = $argv[1];

$repairLinkShop = new RepairLinkShop($config);
$result = $repairLinkShop->getVinInfo($vin);

var_dump($result);

?>