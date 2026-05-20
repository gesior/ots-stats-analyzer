<?php

declare(strict_types=1);

namespace OtsStats\Console;

interface OutputInterface
{
    public function writeln(string $message): void;
}
