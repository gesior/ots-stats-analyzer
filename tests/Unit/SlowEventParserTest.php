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
}
