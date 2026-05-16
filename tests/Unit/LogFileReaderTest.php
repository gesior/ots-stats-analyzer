<?php

declare(strict_types=1);

namespace OtsStats\Tests\Unit;

use OtsStats\Service\LogFileReader;
use PHPUnit\Framework\TestCase;

final class LogFileReaderTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/ots-log-reader-' . uniqid('', true) . '.log';
        file_put_contents($this->tmpFile, "line1\nline2\nline3\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testStreamModeReadsAllLines(): void
    {
        $lines = iterator_to_array(LogFileReader::lines($this->tmpFile, 0, 'stream', 1024, 1024));

        $this->assertCount(3, $lines);
        $this->assertSame("line1\n", $lines[0]['line']);
        $this->assertSame(strlen("line1\n"), $lines[0]['byte_offset']);
        $this->assertSame(strlen("line1\nline2\nline3\n"), $lines[2]['byte_offset']);
    }

    public function testChunkModeResumesFromOffset(): void
    {
        $offset = strlen("line1\nline2\n");
        $lines = iterator_to_array(LogFileReader::lines($this->tmpFile, $offset, 'chunk', 4, 1024));

        $this->assertCount(1, $lines);
        $this->assertSame("line3\n", $lines[0]['line']);
        $this->assertSame(strlen("line1\nline2\nline3\n"), $lines[0]['byte_offset']);
    }

    public function testFileModeLoadsSmallFile(): void
    {
        $lines = iterator_to_array(LogFileReader::lines($this->tmpFile, 0, 'file', 1024, 1024));

        $this->assertCount(3, $lines);
        $this->assertSame("line3\n", $lines[2]['line']);
    }
}
