<?php

declare(strict_types=1);

namespace OtsStats\Parser;

use OtsStats\Util\TimestampParser;

final class SlowEventParser
{
    private const LINE_PATTERN = '/^\[(\d{1,2})\/(\d{1,2})\/(\d{4}) (\d{1,2}):(\d{1,2}):(\d{1,2})\] Execution time: (\d+) ms - (.+) - (.*)$/';

    /**
     * @return array{occurred_at: int, execution_ms: int, description: string, detail: string}|null
     */
    public function parseLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            return null;
        }

        if (!preg_match(self::LINE_PATTERN, $line, $m)) {
            return null;
        }

        $occurredAt = TimestampParser::toUnixFromParts(
            (int) $m[1],
            (int) $m[2],
            (int) $m[3],
            (int) $m[4],
            (int) $m[5],
            (int) $m[6],
        );

        $description = trim($m[8]);
        $detail = trim($m[9]);

        // SQL logs often repeat full query after second " - "
        if ($detail !== '' && str_starts_with($detail, $description)) {
            $description = $detail;
        } elseif ($detail !== '' && strlen($detail) > strlen($description)) {
            $description = $detail;
        }

        return [
            'occurred_at' => $occurredAt,
            'execution_ms' => (int) $m[7],
            'description' => $description,
            'detail' => $detail,
        ];
    }
}
