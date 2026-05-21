<?php

declare(strict_types=1);

namespace OtsStats\Service;

use RuntimeException;

final class LogFileIdentity
{
    public static function readFirstLineHash(string $path): ?string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open: {$path}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line !== '') {
                    return hash('sha256', $line);
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }
}
