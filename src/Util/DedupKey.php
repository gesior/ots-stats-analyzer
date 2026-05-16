<?php

declare(strict_types=1);

namespace OtsStats\Util;

final class DedupKey
{
    public static function slow(
        string $source,
        string $severity,
        int $occurredAt,
        int $executionMs,
        string $description,
        string $detail,
    ): string {
        return implode("\0", [
            $source,
            $severity,
            (string) $occurredAt,
            (string) $executionMs,
            $description,
            $detail,
        ]);
    }

    public static function cpuStat(
        string $source,
        int $reportedAt,
        int $descriptionId,
        int $timeMs,
        int $calls,
        string $relUsage,
        string $realUsage,
    ): string {
        return implode("\0", [
            $source,
            (string) $reportedAt,
            (string) $descriptionId,
            (string) $timeMs,
            (string) $calls,
            $relUsage,
            $realUsage,
        ]);
    }
}
