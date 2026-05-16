<?php

declare(strict_types=1);

namespace OtsStats\Service;

use Generator;
use RuntimeException;

final class LogFileReader
{
    /**
     * @return Generator<int, array{line: string, byte_offset: int}>
     */
    public static function lines(
        string $path,
        int $startOffset,
        string $readMode,
        int $chunkBytes,
        int $maxFileLoadBytes,
    ): Generator {
        $fileSize = (int) filesize($path);

        if ($readMode === 'file' && $fileSize <= $maxFileLoadBytes && $startOffset < $fileSize) {
            yield from self::linesFromString(
                (string) file_get_contents($path, false, null, $startOffset),
                $startOffset,
            );

            return;
        }

        if ($readMode === 'chunk') {
            yield from self::linesFromChunks($path, $startOffset, $chunkBytes);

            return;
        }

        yield from self::linesFromStream($path, $startOffset);
    }

    /**
     * @return Generator<int, array{line: string, byte_offset: int}>
     */
    private static function linesFromStream(string $path, int $startOffset): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open: {$path}");
        }

        if ($startOffset > 0) {
            fseek($handle, $startOffset);
        }

        $byteOffset = $startOffset;

        try {
            while (($line = fgets($handle)) !== false) {
                $byteOffset += strlen($line);
                yield ['line' => $line, 'byte_offset' => $byteOffset];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Generator<int, array{line: string, byte_offset: int}>
     */
    private static function linesFromChunks(string $path, int $startOffset, int $chunkBytes): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open: {$path}");
        }

        if ($startOffset > 0) {
            fseek($handle, $startOffset);
        }

        try {
            yield from self::linesFromBuffer($handle, $startOffset, $chunkBytes);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return Generator<int, array{line: string, byte_offset: int}>
     */
    private static function linesFromBuffer($handle, int $startOffset, int $chunkBytes): Generator
    {
        $lineStart = $startOffset;
        $buffer = '';

        while (!feof($handle)) {
            $chunk = fread($handle, $chunkBytes);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;

            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos + 1);
                $buffer = substr($buffer, $newlinePos + 1);
                $lineStart += strlen($line);
                yield ['line' => $line, 'byte_offset' => $lineStart];
            }
        }

        if ($buffer !== '') {
            $lineStart += strlen($buffer);
            yield ['line' => $buffer, 'byte_offset' => $lineStart];
        }
    }

    /**
     * @return Generator<int, array{line: string, byte_offset: int}>
     */
    private static function linesFromString(string $content, int $startOffset): Generator
    {
        if ($content === '') {
            return;
        }

        $lineStart = $startOffset;
        $buffer = $content;

        while (($newlinePos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $newlinePos + 1);
            $buffer = substr($buffer, $newlinePos + 1);
            $lineStart += strlen($line);
            yield ['line' => $line, 'byte_offset' => $lineStart];
        }

        if ($buffer !== '') {
            $lineStart += strlen($buffer);
            yield ['line' => $buffer, 'byte_offset' => $lineStart];
        }
    }
}
