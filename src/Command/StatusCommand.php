<?php

declare(strict_types=1);

namespace OtsStats\Command;

use OtsStats\Repository\Database;
use OtsStats\Repository\ImportStateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'status', description: 'Show import state and database row counts')]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly string $root,
        private readonly array $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('db', null, InputOption::VALUE_REQUIRED, 'SQLite database path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbPath = $this->resolvePath((string) ($input->getOption('db') ?? $this->config['db_path']));
        $schemaPath = $this->root . '/database/schema.sql';
        $indexesPath = $this->root . '/database/indexes.sql';

        if (!is_file($dbPath)) {
            $output->writeln('<comment>Database not found. Run import first.</comment>');

            return Command::SUCCESS;
        }

        $database = new Database($dbPath, $schemaPath, $indexesPath);
        $pdo = $database->pdo();
        $importState = new ImportStateRepository($pdo);

        $output->writeln('<info>Row counts</info>');
        $counts = new Table($output);
        $counts->setHeaders(['Table', 'Rows']);
        foreach (['descriptions', 'cpu_reports', 'cpu_stats', 'slow_events'] as $table) {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $counts->addRow([$table, (string) $count]);
        }
        $counts->render();

        $output->writeln('');
        $output->writeln('<info>Import file offsets</info>');
        $files = new Table($output);
        $files->setHeaders(['File', 'Offset', 'Size', 'Max timestamp']);
        foreach ($importState->listAll() as $row) {
            $maxTs = $row['max_occurred_at'] !== null
                ? date('Y-m-d H:i:s', (int) $row['max_occurred_at'])
                : '-';
            $files->addRow([
                $row['file_key'],
                self::formatBytes((int) $row['byte_offset']),
                self::formatBytes((int) $row['file_size']),
                $maxTs,
            ]);
        }
        $files->render();

        return Command::SUCCESS;
    }

    private static function formatBytes(int $bytes): string
    {
        $gib = $bytes / 1024 / 1024 / 1024;
        if ($gib >= 1) {
            return sprintf('%.2f GiB', $gib);
        }

        $mib = $bytes / 1024 / 1024;
        if ($mib >= 0.01) {
            return sprintf('%.2f MiB', $mib);
        }

        return sprintf('%.1f KiB', $bytes / 1024);
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || $path[0] === '/' || preg_match('#^[A-Za-z]:[/\\\\]#', $path)) {
            return $path;
        }

        return $this->root . '/' . $path;
    }
}
