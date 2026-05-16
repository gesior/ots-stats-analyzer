<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use PDO;

final class ImportStateRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function get(string $fileKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM import_files WHERE file_key = :key');
        $stmt->execute(['key' => $fileKey]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function save(
        string $fileKey,
        string $path,
        int $fileSize,
        int $fileMtime,
        int $byteOffset,
        ?int $maxOccurredAt,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_files (file_key, path, file_size, file_mtime, byte_offset, max_occurred_at, updated_at)
             VALUES (:file_key, :path, :file_size, :file_mtime, :byte_offset, :max_occurred_at, :updated_at)
             ON CONFLICT(file_key) DO UPDATE SET
                path = excluded.path,
                file_size = excluded.file_size,
                file_mtime = excluded.file_mtime,
                byte_offset = excluded.byte_offset,
                max_occurred_at = excluded.max_occurred_at,
                updated_at = excluded.updated_at',
        );
        $stmt->execute([
            'file_key' => $fileKey,
            'path' => $path,
            'file_size' => $fileSize,
            'file_mtime' => $fileMtime,
            'byte_offset' => $byteOffset,
            'max_occurred_at' => $maxOccurredAt,
            'updated_at' => time(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query('SELECT * FROM import_files ORDER BY file_key')->fetchAll();
    }
}
