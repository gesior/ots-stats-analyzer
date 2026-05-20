<?php

declare(strict_types=1);

namespace OtsStats\Command;

use OtsStats\Console\CliInput;
use OtsStats\Console\OutputInterface;
use OtsStats\Repository\Database;
use OtsStats\Service\ImportOrchestrator;

final class ImportCommand
{
    public function __construct(
        private readonly string $root,
        private readonly array $config,
    ) {
    }

    public function execute(CliInput $input, OutputInterface $output): int
    {
        $memoryLimit = $input->getOption('memory-limit') ?? $this->config['memory_limit'];
        ini_set('memory_limit', (string) $memoryLimit);

        $dataDir = $this->resolvePath((string) ($input->getOption('data-dir') ?? $this->config['data_dir']));
        $dbPath = $this->resolvePath((string) ($input->getOption('db') ?? $this->config['db_path']));
        $schemaPath = $this->root . '/database/schema.sql';
        $indexesPath = $this->root . '/database/indexes.sql';

        $config = $this->config;
        if ($input->getOption('dedup-days') !== null) {
            $config['dedup_days'] = (int) $input->getOption('dedup-days');
        }

        $progressInterval = $input->getOption('progress-interval') !== null
            ? (float) $input->getOption('progress-interval')
            : (float) $this->config['progress_interval_seconds'];

        $database = new Database($dbPath, $schemaPath, $indexesPath);
        $orchestrator = new ImportOrchestrator($database, $config, $dataDir);

        return $orchestrator->run($output, $progressInterval);
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || $path[0] === '/' || preg_match('#^[A-Za-z]:[/\\\\]#', $path)) {
            return $path;
        }

        return $this->root . '/' . $path;
    }
}
