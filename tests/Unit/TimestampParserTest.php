<?php

declare(strict_types=1);

namespace OtsStats\Tests\Unit;

use DateTimeImmutable;
use OtsStats\Util\TimestampParser;
use PHPUnit\Framework\TestCase;

final class TimestampParserTest extends TestCase
{
    public function testParseFromLine(): void
    {
        $dt = TimestampParser::parseFromLine('[14/3/2026 7:39:3] Execution time: 10 ms');
        $this->assertInstanceOf(DateTimeImmutable::class, $dt);
        $this->assertSame(2026, (int) $dt->format('Y'));
        $this->assertSame(3, (int) $dt->format('n'));
        $this->assertSame(14, (int) $dt->format('j'));
        $this->assertSame(7, (int) $dt->format('G'));
        $this->assertSame(39, (int) $dt->format('i'));
        $this->assertSame(3, (int) $dt->format('s'));
    }

    public function testInvalidLineReturnsNull(): void
    {
        $this->assertNull(TimestampParser::parseFromLine('not a timestamp'));
    }
}
