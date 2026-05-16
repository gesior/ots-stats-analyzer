<?php

declare(strict_types=1);

namespace OtsStats\Tests\Unit;

use OtsStats\Parser\CpuReportParser;
use PHPUnit\Framework\TestCase;

final class CpuReportParserTest extends TestCase
{
    public function testMultilineLuaDescription(): void
    {
        $lines = file(__DIR__ . '/../fixtures/lua.log', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);

        $parser = new CpuReportParser();
        $stats = [];

        foreach ($lines as $line) {
            foreach ($parser->feedLine($line) as $event) {
                if (($event['type'] ?? '') === 'stat') {
                    $stats[] = $event;
                }
            }
        }

        foreach ($parser->finish() as $event) {
            if (($event['type'] ?? '') === 'stat') {
                $stats[] = $event;
            }
        }

        $this->assertGreaterThanOrEqual(2, count($stats));
        $multiline = null;
        foreach ($stats as $stat) {
            if (str_contains($stat['description'], 'domodlib')) {
                $multiline = $stat;
                break;
            }
        }

        $this->assertNotNull($multiline);
        $this->assertStringContainsString('onStartup()', $multiline['description']);
        $this->assertStringContainsString(':onStartup', $multiline['description']);
    }
}
