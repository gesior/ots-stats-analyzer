<?php

declare(strict_types=1);

namespace OtsStats\Service;

final class DedupIndex
{
    /** @var array<string, true> */
    private array $keys = [];

    public function add(string $key): void
    {
        $this->keys[$key] = true;
    }

    public function has(string $key): bool
    {
        return isset($this->keys[$key]);
    }

    public function count(): int
    {
        return count($this->keys);
    }
}
