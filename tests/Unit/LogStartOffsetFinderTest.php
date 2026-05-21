<?php

declare(strict_types=1);

namespace OtsStats\Tests\Unit;

use OtsStats\Service\LogStartOffsetFinder;
use OtsStats\Util\TimestampParser;
use PHPUnit\Framework\TestCase;

final class LogStartOffsetFinderTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/ots-log-seek-' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testFindSkipsOldLines(): void
    {
        $oldTs = time() - (60 * 86400);
        $newTs = time() - (5 * 86400);
        $oldLine = $this->formatLine($oldTs, 'old');
        $newLine = $this->formatLine($newTs, 'new');

        file_put_contents($this->tmpFile, $oldLine . "\n" . str_repeat($oldLine . "\n", 100) . $newLine . "\n");

        $cutoff = time() - (30 * 86400);
        $offset = LogStartOffsetFinder::find($this->tmpFile, $cutoff);

        $this->assertGreaterThan(0, $offset);

        $tail = (string) file_get_contents($this->tmpFile, false, null, $offset);
        $this->assertStringStartsWith('[' . date('j/n/Y', $newTs), ltrim($tail));
    }

    public function testFindWorksOnCpuReportBlocksWithStatLines(): void
    {
        $oldTs = time() - (60 * 86400);
        $newTs = time() - (5 * 86400);

        $content = $this->formatCpuReport($oldTs);
        for ($i = 0; $i < 50; ++$i) {
            $content .= $this->formatCpuReport($oldTs + $i);
        }
        $content .= $this->formatCpuReport($newTs);

        file_put_contents($this->tmpFile, $content);

        $cutoff = time() - (30 * 86400);
        $offset = LogStartOffsetFinder::find($this->tmpFile, $cutoff);

        $this->assertGreaterThan(0, $offset);
        $this->assertLessThan((int) filesize($this->tmpFile), $offset);

        $tail = (string) file_get_contents($this->tmpFile, false, null, $offset);
        $this->assertStringStartsWith('[' . date('j/n/Y', $newTs), ltrim($tail));
    }

    public function testFindReturnsFileSizeWhenAllLinesAreTooOld(): void
    {
        $oldTs = time() - (90 * 86400);
        file_put_contents($this->tmpFile, $this->formatLine($oldTs, 'old') . "\n");

        $cutoff = time() - (30 * 86400);
        $offset = LogStartOffsetFinder::find($this->tmpFile, $cutoff);

        $this->assertSame((int) filesize($this->tmpFile), $offset);
    }

    private function formatLine(int $timestamp, string $suffix): string
    {
        $dt = TimestampParser::fromParts(
            (int) date('j', $timestamp),
            (int) date('n', $timestamp),
            (int) date('Y', $timestamp),
            (int) date('G', $timestamp),
            (int) date('i', $timestamp),
            (int) date('s', $timestamp),
        );

        return sprintf('[%s] Execution time: 10 ms - %s', $dt->format('j/n/Y G:i:s'), $suffix);
    }

    private function formatCpuReport(int $timestamp): string
    {
        $dt = TimestampParser::fromParts(
            (int) date('j', $timestamp),
            (int) date('n', $timestamp),
            (int) date('Y', $timestamp),
            (int) date('G', $timestamp),
            (int) date('i', $timestamp),
            (int) date('s', $timestamp),
        );

        return sprintf(
            "[%s]\nThread: 1 Cpu usage: 1.0%% Idle: 99.0%% Other: 0.0%% Players online: 0\n"
            . " Time (ms)     Calls     Rel usage %%    Real usage %% Description\n"
            . "     1000         1       50.00000%%       50.00000%% testFunction\n\n",
            $dt->format('j/n/Y G:i:s'),
        );
    }
}
