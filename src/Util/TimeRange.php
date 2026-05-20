<?php

declare(strict_types=1);

namespace OtsStats\Util;

use InvalidArgumentException;

final class TimeRange
{
    /** @param array<string, int> $rangeDurations */
    /** @param array<string, int> $bucketDefaults */
    public function __construct(
        private readonly array $rangeDurations,
        private readonly array $bucketDefaults,
        private readonly int $maxChartPoints,
    ) {
    }

    public function validateRange(string $range): void
    {
        if (!isset($this->rangeDurations[$range])) {
            throw new InvalidArgumentException("Invalid range: {$range}");
        }
    }

    public function duration(string $range): int
    {
        $this->validateRange($range);

        return $this->rangeDurations[$range];
    }

    public function bucketSeconds(string $range): int
    {
        $this->validateRange($range);
        $duration = $this->rangeDurations[$range];
        $default = $this->bucketDefaults[$range] ?? 30;
        $minBucket = (int) ceil($duration / $this->maxChartPoints);

        return max($default, $minBucket);
    }

    /**
     * @return array{start: int, end: int, bucket_seconds: int}
     */
    public function resolve(int $end, string $range): array
    {
        $this->validateRange($range);
        $duration = $this->rangeDurations[$range];

        return [
            'start' => $end - $duration,
            'end' => $end,
            'bucket_seconds' => $this->bucketSeconds($range),
        ];
    }
}
