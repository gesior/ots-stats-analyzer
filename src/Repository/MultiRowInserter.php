<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use PDO;
use PDOStatement;

/**
 * Cached multi-row INSERT with positional placeholders (faster than large named binds in PHP PDO).
 */
final class MultiRowInserter
{
    /** @var array<int, PDOStatement> */
    private array $statements = [];

    private readonly int $columnsPerRow;

    /**
     * @param list<string> $columns
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table,
        private readonly array $columns,
        private readonly int $maxRowsPerStatement,
        private readonly bool $orIgnore = false,
    ) {
        $this->columnsPerRow = count($this->columns);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function insert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, $this->maxRowsPerStatement) as $chunk) {
            $this->insertChunk($chunk);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function insertChunk(array $rows): void
    {
        $stmt = $this->statementFor(count($rows));
        $params = [];

        foreach ($rows as $row) {
            foreach ($this->columns as $column) {
                $params[] = $row[$column];
            }
        }

        $stmt->execute($params);
    }

    private function statementFor(int $rowCount): PDOStatement
    {
        if (!isset($this->statements[$rowCount])) {
            $this->statements[$rowCount] = $this->pdo->prepare($this->buildSql($rowCount));
        }

        return $this->statements[$rowCount];
    }

    private function buildSql(int $rowCount): string
    {
        $columnList = implode(', ', $this->columns);
        $oneRow = '(' . implode(', ', array_fill(0, $this->columnsPerRow, '?')) . ')';
        $valueGroups = implode(', ', array_fill(0, $rowCount, $oneRow));

        $verb = $this->orIgnore ? 'INSERT OR IGNORE INTO' : 'INSERT INTO';

        return "{$verb} {$this->table} ({$columnList}) VALUES {$valueGroups}";
    }
}
