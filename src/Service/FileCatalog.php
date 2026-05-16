<?php

declare(strict_types=1);

namespace OtsStats\Service;

final class FileCatalog
{
    /**
     * @param list<string> $sources
     * @return list<array{file_key: string, source: string, type: string, path: string, severity: ?string}>
     */
    public static function enumerate(string $dataDir, array $sources): array
    {
        $files = [];

        foreach ($sources as $source) {
            $cpuPath = $dataDir . DIRECTORY_SEPARATOR . $source . '.log';
            if (is_file($cpuPath)) {
                $files[] = [
                    'file_key' => "{$source}:cpu",
                    'source' => $source,
                    'type' => 'cpu',
                    'path' => $cpuPath,
                    'severity' => null,
                ];
            }

            foreach (['slow' => 'slow', 'very_slow' => 'very_slow'] as $suffix => $severity) {
                $path = $dataDir . DIRECTORY_SEPARATOR . $source . '_' . $suffix . '.log';
                if (is_file($path)) {
                    $files[] = [
                        'file_key' => "{$source}:{$severity}",
                        'source' => $source,
                        'type' => $severity,
                        'path' => $path,
                        'severity' => $severity,
                    ];
                }
            }
        }

        return $files;
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
