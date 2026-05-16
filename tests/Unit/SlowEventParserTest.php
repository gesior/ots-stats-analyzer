<?php

declare(strict_types=1);

namespace OtsStats\Tests\Unit;

use OtsStats\Parser\SlowEventParser;
use PHPUnit\Framework\TestCase;

final class SlowEventParserTest extends TestCase
{
    public function testParseDispatcherLine(): void
    {
        $parser = new SlowEventParser();
        $result = $parser->parseLine(
            '[12/3/2026 16:30:56] Execution time: 32 ms - std::bind(&Game::checkDecay, this) - checkDecay',
        );

        $this->assertNotNull($result);
        $this->assertSame(32, $result['execution_ms']);
        $this->assertStringContainsString('checkDecay', $result['description']);
        $this->assertSame('checkDecay', $result['detail']);
    }

    public function testParseSpecialSavePlayer(): void
    {
        $parser = new SlowEventParser();
        $result = $parser->parseLine(
            '[14/3/2026 7:39:25] Execution time: 45 ms - savePlayer - Mohamed',
        );

        $this->assertNotNull($result);
        $this->assertSame('savePlayer', $result['description']);
        $this->assertSame('Mohamed', $result['detail']);
    }

    public function testRejectsInvalidLine(): void
    {
        $parser = new SlowEventParser();

        $this->assertNull($parser->parseLine('not a log line'));
        $this->assertNull($parser->parseLine('[1/1/2026 0:00:00] something else'));
    }

    public function testParseSqlStyleLongDescription(): void
    {
        $parser = new SlowEventParser();
        $query = 'SELECT * FROM players WHERE id = 1';
        $line = "[1/1/2026 12:00:00] Execution time: 10 ms - {$query} - {$query}";
        $result = $parser->parseLine($line);

        $this->assertNotNull($result);
        $this->assertSame($query, $result['description']);
        $this->assertSame($query, $result['detail']);
    }
}
