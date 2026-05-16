#!/usr/bin/env php
<?php

declare(strict_types=1);

use OtsStats\Command\ImportCommand;
use OtsStats\Command\StatusCommand;
use Symfony\Component\Console\Application;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$config = require $root . '/config/import.php';
$memoryLimit = $config['memory_limit'] ?? '32G';
ini_set('memory_limit', $memoryLimit);

$application = new Application('OTS Stats Analyzer', '1.0.0');
$application->add(new ImportCommand($root, $config));
$application->add(new StatusCommand($root, $config));
$application->run();
