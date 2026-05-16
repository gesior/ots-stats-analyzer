<?php

declare(strict_types=1);

namespace OtsStats\Parser;

use OtsStats\Util\TimestampParser;

final class SlowEventParser
{
    /**
     * @return array{occurred_at: int, execution_ms: int, description: string, detail: string}|null
     */
    public function parseLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if ($line === '' || $line[0] !== '[') {
            return null;
        }

        $bracketEnd = strpos($line, ']');
        if ($bracketEnd === false) {
            return null;
        }

        $timestampPart = substr($line, 1, $bracketEnd - 1);
        $rest = substr($line, $bracketEnd + 2);
        if (!str_starts_with($rest, 'Execution time: ')) {
            return null;
        }

        $msStart = 16;
        $msEnd = strpos($rest, ' ms - ', $msStart);
        if ($msEnd === false) {
            return null;
        }

        $executionMs = (int) substr($rest, $msStart, $msEnd - $msStart);
        $payload = substr($rest, $msEnd + 6);
        $separator = strrpos($payload, ' - ');
        if ($separator === false) {
            return null;
        }

        $description = trim(substr($payload, 0, $separator));
        $detail = trim(substr($payload, $separator + 3));

        $parts = self::parseTimestamp($timestampPart);
        if ($parts === null) {
            return null;
        }

        $occurredAt = TimestampParser::toUnixFromParts(
            $parts[0],
            $parts[1],
            $parts[2],
            $parts[3],
            $parts[4],
            $parts[5],
        );

        if ($detail !== '' && str_starts_with($detail, $description)) {
            $description = $detail;
        } elseif ($detail !== '' && strlen($detail) > strlen($description)) {
            $description = $detail;
        }

        return [
            'occurred_at' => $occurredAt,
            'execution_ms' => $executionMs,
            'description' => $description,
            'detail' => $detail,
        ];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}|null
     */
    private static function parseTimestamp(string $timestamp): ?array
    {
        $space = strpos($timestamp, ' ');
        if ($space === false) {
            return null;
        }

        $date = substr($timestamp, 0, $space);
        $time = substr($timestamp, $space + 1);

        $dateParts = explode('/', $date);
        if (count($dateParts) !== 3) {
            return null;
        }

        $timeParts = explode(':', $time);
        if (count($timeParts) !== 3) {
            return null;
        }

        return [
            (int) $dateParts[0],
            (int) $dateParts[1],
            (int) $dateParts[2],
            (int) $timeParts[0],
            (int) $timeParts[1],
            (int) $timeParts[2],
        ];
    }
}
