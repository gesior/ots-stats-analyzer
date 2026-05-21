#!/usr/bin/env php
<?php

declare(strict_types=1);

use OtsStats\Command\ImportCommand;
use OtsStats\Command\StatusCommand;
use OtsStats\Console\Application;
use OtsStats\Console\ConsoleOutput;
use OtsStats\Service\ImportProgressReporter;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$config = require $root . '/config/import.php';
$memoryLimit = $config['memory_limit'] ?? '32G';
ini_set('memory_limit', $memoryLimit);

$application = new Application('OTS Stats Analyzer', '1.0.0');

$importCmd = new ImportCommand($root, $config);
$application->add('import', 'Import OTS statistics logs into SQLite', [$importCmd, 'execute']);

$statusCmd = new StatusCommand($root, $config);
$application->add('status', 'Show import state and database row counts', [$statusCmd, 'execute']);

$exitCode = $application->run();

$output = new ConsoleOutput();
$output->writeln(sprintf(
    '<comment>Peak PHP memory allocation: %s</comment>',
    ImportProgressReporter::formatBytes(memory_get_peak_usage()),
));
$output->writeln(sprintf(
    '<comment>Peak PHP memory real usage: %s</comment>',
    ImportProgressReporter::formatBytes(memory_get_peak_usage(true)),
));

exit($exitCode);
