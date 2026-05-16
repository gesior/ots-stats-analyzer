<?php

declare(strict_types=1);

namespace OtsStats\Parser;

use OtsStats\Util\TimestampParser;

/**
 * Stateful parser for CPU report blocks in .log files.
 */
final class CpuReportParser
{
    private const DATA_ROW_PATTERN = '/^\s+(\d+)\s+(\d+)\s+([\d.]+)%\s+([\d.]+)%\s+(.*)$/';
    private const DISPATCHER_META_PATTERN = '/^Thread:\s*(\d+)\s+Cpu usage:\s*([\d.+-]+)%\s+Idle:\s*([\d.+-]+)%\s+Other:\s*([\d.+-]+)%\s+Players online:\s*(\d+)/';
    private const COLUMN_HEADER = 'Time (ms)';

    private ?int $currentReportedAt = null;
    private ?int $threadId = null;
    private ?float $cpuUsage = null;
    private ?float $idle = null;
    private ?float $other = null;
    private ?int $playersOnline = null;
    private bool $awaitingColumnHeader = false;
    private bool $inReport = false;

    private ?int $pendingTimeMs = null;
    private ?int $pendingCalls = null;
    private ?float $pendingRelUsage = null;
    private ?float $pendingRealUsage = null;
    private string $pendingDescription = '';

    public function reset(): void
    {
        $this->currentReportedAt = null;
        $this->threadId = null;
        $this->cpuUsage = null;
        $this->idle = null;
        $this->other = null;
        $this->playersOnline = null;
        $this->awaitingColumnHeader = false;
        $this->inReport = false;
        $this->clearPending();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function feedLine(string $line): array
    {
        $line = rtrim($line, "\r\n");
        $events = [];

        if ($line === '') {
            return $events;
        }

        $ts = TimestampParser::parseFromLine($line);
        if ($ts !== null) {
            $flushed = $this->flushPendingStat();
            if ($flushed !== null) {
                $events[] = $flushed;
            }

            $this->startReport($ts->getTimestamp());
            $this->awaitingColumnHeader = true;
            $events[] = [
                'type' => 'report_start',
                'reported_at' => $ts->getTimestamp(),
            ];

            return $events;
        }

        if (!$this->inReport) {
            return $events;
        }

        if ($this->awaitingColumnHeader) {
            if (preg_match(self::DISPATCHER_META_PATTERN, $line, $m)) {
                $this->threadId = (int) $m[1];
                $this->cpuUsage = (float) $m[2];
                $this->idle = (float) $m[3];
                $this->other = (float) $m[4];
                $this->playersOnline = (int) $m[5];

                return $events;
            }

            if (str_contains($line, self::COLUMN_HEADER)) {
                $this->awaitingColumnHeader = false;
            }

            return $events;
        }

        if (preg_match(self::DATA_ROW_PATTERN, $line, $m)) {
            $flushed = $this->flushPendingStat();
            if ($flushed !== null) {
                $events[] = $flushed;
            }

            $this->pendingTimeMs = (int) $m[1];
            $this->pendingCalls = (int) $m[2];
            $this->pendingRelUsage = (float) $m[3];
            $this->pendingRealUsage = (float) $m[4];
            $this->pendingDescription = $m[5];

            return $events;
        }

        if ($this->pendingDescription !== '') {
            $this->pendingDescription .= "\n" . $line;
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    public function finish(): array
    {
        $events = [];
        $flushed = $this->flushPendingStat();
        if ($flushed !== null) {
            $events[] = $flushed;
        }

        $this->reset();

        return $events;
    }

    private function startReport(int $reportedAt): void
    {
        $this->currentReportedAt = $reportedAt;
        $this->threadId = null;
        $this->cpuUsage = null;
        $this->idle = null;
        $this->other = null;
        $this->playersOnline = null;
        $this->awaitingColumnHeader = true;
        $this->inReport = true;
        $this->clearPending();
    }

    private function clearPending(): void
    {
        $this->pendingTimeMs = null;
        $this->pendingCalls = null;
        $this->pendingRelUsage = null;
        $this->pendingRealUsage = null;
        $this->pendingDescription = '';
    }

    /** @return array<string, mixed>|null */
    private function flushPendingStat(): ?array
    {
        if ($this->pendingDescription === '' || $this->currentReportedAt === null
            || $this->pendingTimeMs === null) {
            $this->clearPending();

            return null;
        }

        $stat = [
            'type' => 'stat',
            'reported_at' => $this->currentReportedAt,
            'thread_id' => $this->threadId,
            'cpu_usage' => $this->cpuUsage,
            'idle' => $this->idle,
            'other' => $this->other,
            'players_online' => $this->playersOnline,
            'time_ms' => $this->pendingTimeMs,
            'calls' => $this->pendingCalls,
            'rel_usage' => $this->pendingRelUsage,
            'real_usage' => $this->pendingRealUsage,
            'description' => trim($this->pendingDescription),
        ];

        $this->clearPending();

        return $stat;
    }
}
