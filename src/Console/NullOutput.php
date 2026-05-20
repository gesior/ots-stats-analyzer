<?php

declare(strict_types=1);

namespace OtsStats\Console;

final class NullOutput implements OutputInterface
{
    public function writeln(string $message): void
    {
        // intentionally empty
    }
}
