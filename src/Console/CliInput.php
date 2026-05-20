<?php

declare(strict_types=1);

namespace OtsStats\Console;

final class CliInput
{
    /** @var array<string, ?string> */
    private array $options = [];

    /** @var list<string> */
    private array $arguments = [];

    /**
     * @param list<string> $argv Raw CLI arguments (without the script name)
     */
    public function __construct(array $argv)
    {
        $this->parse($argv);
    }

    public function getOption(string $name): ?string
    {
        return $this->options[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @param list<string> $argv
     */
    private function parse(array $argv): void
    {
        $count = count($argv);

        for ($i = 0; $i < $count; ++$i) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--')) {
                $rest = substr($arg, 2);

                if ($rest === '') {
                    continue;
                }

                $eqPos = strpos($rest, '=');
                if ($eqPos !== false) {
                    $name = substr($rest, 0, $eqPos);
                    $value = substr($rest, $eqPos + 1);
                    $this->options[$name] = $value;
                } else {
                    // Check if next arg is a value (not starting with --)
                    if ($i + 1 < $count && !str_starts_with($argv[$i + 1], '--')) {
                        $this->options[$rest] = $argv[$i + 1];
                        ++$i;
                    } else {
                        $this->options[$rest] = null;
                    }
                }
            } else {
                $this->arguments[] = $arg;
            }
        }
    }
}
