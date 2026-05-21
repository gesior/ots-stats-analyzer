<?php

declare(strict_types=1);

namespace OtsStats\Service;

final class FileCatalog
{
    /**
     * @param list<string> $sources
     * @return list<array{file_key: string, source: string, type: string, path: string, severity: ?string, is_rolling: bool}>
     */
    public static function enumerate(string $dataDir, array $sources): array
    {
        $files = [];
        $seen = [];

        foreach ($sources as $source) {
            foreach (self::discoverForSource($dataDir, $source) as $entry) {
                if (isset($seen[$entry['file_key']])) {
                    continue;
                }

                $seen[$entry['file_key']] = true;
                $files[] = $entry;
            }
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['file_key'], $b['file_key']));

        return $files;
    }

    /**
     * @return list<array{file_key: string, source: string, type: string, path: string, severity: ?string, is_rolling: bool}>
     */
    private static function discoverForSource(string $dataDir, string $source): array
    {
        $entries = [];
        $pattern = $dataDir . DIRECTORY_SEPARATOR . $source . '*.log';
        $paths = glob($pattern) ?: [];

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $basename = basename($path);
            $classification = self::classify($source, $basename);
            if ($classification === null) {
                continue;
            }

            $entries[] = [
                'file_key' => $basename,
                'source' => $source,
                'type' => $classification['type'],
                'path' => $path,
                'severity' => $classification['severity'],
                'is_rolling' => self::isRollingLogName($source, $basename),
            ];
        }

        return $entries;
    }

    /**
     * @return array{type: string, severity: ?string}|null
     */
    private static function classify(string $source, string $basename): ?array
    {
        if (!str_ends_with($basename, '.log')) {
            return null;
        }

        if ($basename === "{$source}_very_slow.log") {
            return ['type' => 'very_slow', 'severity' => 'very_slow'];
        }

        if ($basename === "{$source}_slow.log") {
            return ['type' => 'slow', 'severity' => 'slow'];
        }

        if ($basename === "{$source}.log") {
            return ['type' => 'cpu', 'severity' => null];
        }

        if (str_contains($basename, '_very_slow')) {
            return ['type' => 'very_slow', 'severity' => 'very_slow'];
        }

        if (str_contains($basename, '_slow')) {
            return ['type' => 'slow', 'severity' => 'slow'];
        }

        if (str_starts_with($basename, "{$source}_") || str_starts_with($basename, "{$source}-")) {
            return ['type' => 'cpu', 'severity' => null];
        }

        return null;
    }

    public static function isRollingLogName(string $source, string $basename): bool
    {
        return in_array($basename, [
            "{$source}.log",
            "{$source}_slow.log",
            "{$source}_very_slow.log",
        ], true);
    }

    public static function sessionBytesTotal(array $files): int
    {
        $total = 0;
        foreach ($files as $file) {
            if (is_file($file['path'])) {
                $total += (int) filesize($file['path']);
            }
        }

        return $total;
    }
}
