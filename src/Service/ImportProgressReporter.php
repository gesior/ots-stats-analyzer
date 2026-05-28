<?php

declare(strict_types=1);

namespace OtsStats\Service;

use OtsStats\Console\OutputInterface;

final class ImportProgressReporter
{
    private float $sessionStart;
    private float $fileStart;
    private float $lastReportAt = 0.0;
    private int $bytesAtLastReport = 0;

    private string $currentFileKey = '';
    private int $bytesDone = 0;
    private int $bytesTotal = 0;
    private int $linesRead = 0;
    private int $rowsInserted = 0;
    private int $rowsSkipped = 0;

    private int $sessionBytesDone = 0;
    private int $sessionBytesTotal = 0;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly float $intervalSeconds,
    ) {
        $this->sessionStart = microtime(true);
        $this->fileStart = $this->sessionStart;
    }

    public function setSessionTotalBytes(int $total): void
    {
        $this->sessionBytesTotal = $total;
    }

    public function startFile(string $fileKey, int $bytesTotal, int $startOffset): void
    {
        $this->currentFileKey = $fileKey;
        $this->bytesTotal = $bytesTotal;
        $this->bytesDone = $startOffset;
        $this->linesRead = 0;
        $this->rowsInserted = 0;
        $this->rowsSkipped = 0;
        $this->fileStart = microtime(true);
        $this->lastReportAt = $this->fileStart;
        $this->bytesAtLastReport = $this->bytesDone;
    }

    public function skipFile(string $fileKey): void
    {
        if ($this->intervalSeconds > 0) {
            $this->output->writeln(sprintf('[%s] SKIP (up to date)', $fileKey));
        }
    }

    public function skipFileOutsideWindow(string $fileKey, int $days): void
    {
        $this->output->writeln(sprintf(
            '[%s] SKIP (no records within last %d days; use --days=0 for full history)',
            $fileKey,
            $days,
        ));
    }

    public function tick(int $bytesReadDelta = 0): void
    {
        $this->bytesDone += $bytesReadDelta;
        $this->sessionBytesDone += $bytesReadDelta;
        ++$this->linesRead;

        if ($this->intervalSeconds <= 0) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastReportAt < $this->intervalSeconds) {
            return;
        }

        $this->emitProgress($now);
    }

    public function recordInserted(int $count = 1): void
    {
        $this->rowsInserted += $count;
    }

    public function recordSkipped(int $count = 1): void
    {
        $this->rowsSkipped += $count;
    }

    public function finishFile(): void
    {
        $elapsed = microtime(true) - $this->fileStart;
        $this->output->writeln(sprintf(
            '[%s] DONE in %s | %s | %s lines | inserted %s | skipped %s',
            $this->currentFileKey,
            self::formatDuration($elapsed),
            self::formatBytes($this->bytesTotal),
            self::formatCount($this->linesRead),
            self::formatCount($this->rowsInserted),
            self::formatCount($this->rowsSkipped),
        ));
    }

    private function emitProgress(float $now): void
    {
        $deltaT = max($now - $this->lastReportAt, 0.001);
        $deltaBytes = $this->bytesDone - $this->bytesAtLastReport;
        $speed = $deltaBytes / $deltaT;

        $remainingFile = $this->bytesTotal - $this->bytesDone;
        $etaFile = $speed > 0 ? (int) round($remainingFile / $speed) : null;

        $remainingSession = $this->sessionBytesTotal - $this->sessionBytesDone;
        $etaTotal = $speed > 0 && $remainingSession > 0 ? (int) round($remainingSession / $speed) : null;

        $percent = $this->bytesTotal > 0
            ? round(100.0 * $this->bytesDone / $this->bytesTotal, 1)
            : 100.0;

        $linesPerSec = $this->linesRead / max($now - $this->fileStart, 0.001);

        $this->output->writeln(sprintf(
            '[%s] %s/%s (%.1f%%) | %.1f MiB/s | %.0f lines/s | ins %s skip %s | elapsed %s | ETA file %s | ETA total %s',
            $this->currentFileKey,
            self::formatBytes($this->bytesDone),
            self::formatBytes($this->bytesTotal),
            $percent,
            $speed / 1024 / 1024,
            $linesPerSec,
            self::formatCount($this->rowsInserted),
            self::formatCount($this->rowsSkipped),
            self::formatDuration($now - $this->fileStart),
            $etaFile !== null ? '~' . self::formatDuration((float) $etaFile) : '?',
            $etaTotal !== null ? '~' . self::formatDuration((float) $etaTotal) : '?',
        ));

        $this->lastReportAt = $now;
        $this->bytesAtLastReport = $this->bytesDone;
    }

    public static function formatBytes(int $bytes): string
    {
        $gib = $bytes / 1024 / 1024 / 1024;
        if ($gib >= 1) {
            return sprintf('%.2f GiB', $gib);
        }

        return sprintf('%.2f MiB', $bytes / 1024 / 1024);
    }

    public static function formatDuration(float $seconds): string
    {
        $s = (int) round($seconds);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return sprintf('%02d:%02d:%02d', $h, $m, $sec);
    }

    /**
     * Returns peak resident set size (RSS) in bytes from the OS.
     * On Linux reads VmHWM from /proc/self/status.
     * Falls back to memory_get_peak_usage(true) on other platforms.
     */
    public static function getPeakRssBytes(): int
    {
        $statusFile = '/proc/self/status';
        if (is_readable($statusFile)) {
            $contents = file_get_contents($statusFile);
            if ($contents !== false && preg_match('/VmHWM:\s+(\d+)\s+kB/', $contents, $m)) {
                return (int) $m[1] * 1024;
            }
        }

        return memory_get_peak_usage(true);
    }

    public static function formatCount(int $n): string
    {
        if ($n >= 1_000_000) {
            return sprintf('%.1fM', $n / 1_000_000);
        }
        if ($n >= 1_000) {
            return sprintf('%.1fk', $n / 1_000);
        }

        return (string) $n;
    }
}
