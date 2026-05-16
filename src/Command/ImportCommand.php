<?php

declare(strict_types=1);

namespace OtsStats\Command;

use OtsStats\Repository\Database;
use OtsStats\Service\ImportOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import', description: 'Import OTS statistics logs into SQLite')]
final class ImportCommand extends Command
{
    public function __construct(
        private readonly string $root,
        private readonly array $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('data-dir', null, InputOption::VALUE_REQUIRED, 'Directory with log files')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'SQLite database path')
            ->addOption('dedup-days', null, InputOption::VALUE_REQUIRED, 'Days of dedup keys to load into RAM')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'PHP memory_limit')
            ->addOption('progress-interval', null, InputOption::VALUE_REQUIRED, 'Progress report interval in seconds (0=off)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $memoryLimit = $input->getOption('memory-limit') ?? $this->config['memory_limit'];
        ini_set('memory_limit', (string) $memoryLimit);

        $dataDir = $this->resolvePath((string) ($input->getOption('data-dir') ?? $this->config['data_dir']));
        $dbPath = $this->resolvePath((string) ($input->getOption('db') ?? $this->config['db_path']));
        $schemaPath = $this->root . '/database/schema.sql';

        $config = $this->config;
        if ($input->getOption('dedup-days') !== null) {
            $config['dedup_days'] = (int) $input->getOption('dedup-days');
        }

        $progressInterval = $input->getOption('progress-interval') !== null
            ? (float) $input->getOption('progress-interval')
            : (float) $this->config['progress_interval_seconds'];

        $database = new Database($dbPath, $schemaPath);
        $orchestrator = new ImportOrchestrator($database, $config, $dataDir);

        return $orchestrator->run($output->getErrorOutput(), $progressInterval);
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || $path[0] === '/' || preg_match('#^[A-Za-z]:[/\\\\]#', $path)) {
            return $path;
        }

        return $this->root . '/' . $path;
    }
}
