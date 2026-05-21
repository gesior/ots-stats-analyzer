<?php

declare(strict_types=1);

namespace OtsStats\Service;

use OtsStats\Util\TimestampParser;
use RuntimeException;

final class LogStartOffsetFinder
{
    public static function find(string $path, int $cutoffTimestamp, int $searchFrom = 0): int
    {
        $fileSize = (int) filesize($path);
        if ($fileSize === 0 || $searchFrom >= $fileSize) {
            return $fileSize;
        }

        $low = $searchFrom;
        $high = $fileSize;
        $result = $fileSize;

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            $lineStart = self::findLineStart($path, $mid);
            $found = self::readTimestampAt($path, $lineStart);

            if ($found === null) {
                $low = $mid + 1;
                continue;
            }

            if ($found['timestamp'] >= $cutoffTimestamp) {
                $result = $found['line_offset'];
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $result;
    }

    /**
     * @return array{timestamp: int, line_offset: int}|null
     */
    private static function readTimestampAt(string $path, int $offset): ?array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open: {$path}");
        }

        try {
            fseek($handle, $offset);

            while (($line = fgets($handle)) !== false) {
                $lineOffset = ftell($handle) - strlen($line);
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }

                $timestamp = TimestampParser::toUnix($line);
                if ($timestamp !== null) {
                    return [
                        'timestamp' => $timestamp,
                        'line_offset' => $lineOffset,
                    ];
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    private static function findLineStart(string $path, int $position): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open: {$path}");
        }

        try {
            if ($position === 0) {
                return 0;
            }

            $seek = min($position, (int) filesize($path)) - 1;
            fseek($handle, $seek);

            while ($seek > 0) {
                $char = fgetc($handle);
                if ($char === "\n") {
                    return ftell($handle);
                }

                --$seek;
                fseek($handle, $seek);
            }

            return 0;
        } finally {
            fclose($handle);
        }
    }
}
