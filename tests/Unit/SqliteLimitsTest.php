<?php

declare(strict_types=1);

namespace OtsStats\Tests\Unit;

use OtsStats\Repository\BatchInsert;
use OtsStats\Repository\SqliteLimits;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteLimitsTest extends TestCase
{
    public function testMaxRowsPerStatementIsGreaterThanLegacyCap(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $maxRows = SqliteLimits::maxRowsPerStatement($pdo, 6);

        $this->assertGreaterThan(150, $maxRows);
        $this->assertSame($maxRows, BatchInsert::maxRowsForColumns($pdo, 6));
    }
}
