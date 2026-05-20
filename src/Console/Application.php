<?php

declare(strict_types=1);

namespace OtsStats\Console;

final class Application
{
    /** @var array<string, array{description: string, handler: callable(CliInput, OutputInterface): int}> */
    private array $commands = [];

    public function __construct(
        private readonly string $name,
        private readonly string $version,
    ) {
    }

    /**
     * @param callable(CliInput, OutputInterface): int $handler
     */
    public function add(string $commandName, string $description, callable $handler): void
    {
        $this->commands[$commandName] = [
            'description' => $description,
            'handler' => $handler,
        ];
    }

    /**
     * @param list<string>|null $argv
     */
    public function run(?array $argv = null): int
    {
        $argv = $argv ?? $_SERVER['argv'] ?? [];

        // Remove script name
        array_shift($argv);

        $output = new ConsoleOutput();

        if ($argv === [] || $argv[0] === '--help' || $argv[0] === '-h') {
            $this->showHelp($output);

            return 0;
        }

        $commandName = array_shift($argv);

        if (!isset($this->commands[$commandName])) {
            $output->writeln(sprintf('<error>Unknown command: %s</error>', $commandName));
            $output->writeln('');
            $this->showHelp($output);

            return 1;
        }

        if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
            $output->writeln(sprintf('<info>%s</info> - %s', $commandName, $this->commands[$commandName]['description']));

            return 0;
        }

        $input = new CliInput($argv);

        try {
            return ($this->commands[$commandName]['handler'])($input, $output);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));

            return 1;
        }
    }

    private function showHelp(OutputInterface $output): void
    {
        $output->writeln(sprintf('<info>%s</info> v%s', $this->name, $this->version));
        $output->writeln('');
        $output->writeln('Available commands:');

        foreach ($this->commands as $name => $meta) {
            $output->writeln(sprintf('  <info>%s</info>  %s', str_pad($name, 12), $meta['description']));
        }
    }
}
