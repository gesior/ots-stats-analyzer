<?php

declare(strict_types=1);

namespace OtsStats\Console;

final class ConsoleOutput implements OutputInterface
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream Defaults to STDERR
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? \STDERR;
    }

    public function writeln(string $message): void
    {
        fwrite($this->stream, $this->format($message) . \PHP_EOL);
    }

    private function format(string $message): string
    {
        $replacements = [
            '<info>' => "\033[32m",
            '</info>' => "\033[0m",
            '<comment>' => "\033[33m",
            '</comment>' => "\033[0m",
            '<error>' => "\033[37;41m",
            '</error>' => "\033[0m",
        ];

        return strtr($message, $replacements);
    }
}
