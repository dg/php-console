<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/PhpConsole.php';

$console = new Tracy\PhpConsole;
$console->start();
