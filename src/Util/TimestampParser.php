<?php

declare(strict_types=1);

namespace OtsStats\Util;

use DateTimeImmutable;

final class TimestampParser
{
    private const PATTERN = '/^\[(\d{1,2})\/(\d{1,2})\/(\d{4}) (\d{1,2}):(\d{1,2}):(\d{1,2})\]/';

    public static function parseFromLine(string $line): ?DateTimeImmutable
    {
        if (!preg_match(self::PATTERN, $line, $m)) {
            return null;
        }

        return self::fromParts(
            (int) $m[1],
            (int) $m[2],
            (int) $m[3],
            (int) $m[4],
            (int) $m[5],
            (int) $m[6],
        );
    }

    public static function toUnix(string $line): ?int
    {
        $dt = self::parseFromLine($line);

        return $dt?->getTimestamp();
    }

    public static function fromParts(int $day, int $month, int $year, int $hour, int $minute, int $second): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second,
        ));
    }

    public static function toUnixFromParts(int $day, int $month, int $year, int $hour, int $minute, int $second): int
    {
        return self::fromParts($day, $month, $year, $hour, $minute, $second)->getTimestamp();
    }
}
