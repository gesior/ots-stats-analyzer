<?php

declare(strict_types=1);

namespace OtsStats\Console;

final class BufferedOutput implements OutputInterface
{
    private string $buffer = '';

    public function writeln(string $message): void
    {
        $this->buffer .= $message . \PHP_EOL;
    }

    /**
     * Returns the buffered content and resets the buffer.
     */
    public function fetch(): string
    {
        $content = $this->buffer;
        $this->buffer = '';

        return $content;
    }
}
