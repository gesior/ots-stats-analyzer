<?php

declare(strict_types=1);

namespace OtsStats\Console;

final class Table
{
    /** @var list<string> */
    private array $headers = [];

    /** @var list<list<string>> */
    private array $rows = [];

    public function __construct(
        private readonly OutputInterface $output,
    ) {
    }

    /** @param list<string> $headers */
    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    /** @param list<string> $row */
    public function addRow(array $row): self
    {
        $this->rows[] = $row;

        return $this;
    }

    public function render(): void
    {
        $colCount = count($this->headers);
        $widths = array_fill(0, $colCount, 0);

        foreach ($this->headers as $i => $header) {
            $widths[$i] = max($widths[$i], mb_strlen($header));
        }

        foreach ($this->rows as $row) {
            foreach ($row as $i => $cell) {
                if ($i < $colCount) {
                    $widths[$i] = max($widths[$i], mb_strlen($cell));
                }
            }
        }

        $separator = '+';
        foreach ($widths as $w) {
            $separator .= str_repeat('-', $w + 2) . '+';
        }

        $this->output->writeln($separator);
        $this->output->writeln($this->formatRow($this->headers, $widths));
        $this->output->writeln($separator);

        foreach ($this->rows as $row) {
            $this->output->writeln($this->formatRow($row, $widths));
        }

        $this->output->writeln($separator);
    }

    /** @param list<string> $row @param list<int> $widths */
    private function formatRow(array $row, array $widths): string
    {
        $line = '|';
        foreach ($widths as $i => $w) {
            $cell = $row[$i] ?? '';
            $line .= ' ' . str_pad($cell, $w) . ' |';
        }

        return $line;
    }
}
