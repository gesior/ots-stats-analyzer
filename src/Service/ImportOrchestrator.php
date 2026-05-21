<?php

declare(strict_types=1);

namespace OtsStats\Service;

use OtsStats\Parser\CpuReportParser;
use OtsStats\Parser\SlowEventParser;
use OtsStats\Repository\CpuReportRepository;
use OtsStats\Repository\Database;
use OtsStats\Repository\DescriptionRepository;
use OtsStats\Repository\SqliteLimits;
use OtsStats\Repository\ImportStateRepository;
use OtsStats\Repository\SlowEventRepository;
use OtsStats\Util\DedupKey;
use OtsStats\Console\OutputInterface;

final class ImportOrchestrator
{
    private readonly DescriptionRepository $descriptions;
    private readonly SlowEventRepository $slowEvents;
    private readonly CpuReportRepository $cpuReports;
    private readonly ImportStateRepository $importState;

    /** @var array<string, true> */
    private array $slowDedup = [];

    /** @var array<string, true> */
    private array $cpuDedup = [];

    /** @var array<string, ?int> */
    private array $maxSlowAt = [];

    /** @var array<string, ?int> */
    private array $maxCpuAt = [];

    private int $dedupCutoff;
    private ?int $importCutoff = null;
    private bool $isIncremental;
    private int $checkpointBytes;
    private string $readMode;
    private int $readChunkBytes;
    private int $maxFileLoadBytes;

    public function __construct(
        private readonly Database $database,
        private readonly array $config,
        private readonly string $dataDir,
    ) {
        if (isset($config['insert_chunk_rows'])) {
            SqliteLimits::setInsertChunkCap((int) $config['insert_chunk_rows']);
        }

        $pdo = $database->pdo();
        $this->descriptions = new DescriptionRepository($pdo);
        $this->slowEvents = new SlowEventRepository($pdo);
        $this->cpuReports = new CpuReportRepository($pdo);
        $this->importState = new ImportStateRepository($pdo);
        $this->checkpointBytes = (int) ($config['checkpoint_bytes'] ?? 32 * 1024 * 1024);
        $this->readMode = (string) ($config['read_mode'] ?? 'stream');
        $this->readChunkBytes = (int) ($config['read_chunk_bytes'] ?? 67_108_864);
        $this->maxFileLoadBytes = (int) ($config['max_file_load_bytes'] ?? 67_108_864);
    }

    public function run(OutputInterface $output, float $progressInterval): int
    {
        $sources = $this->config['sources'];
        $dedupDays = (int) $this->config['dedup_days'];
        $importDays = (int) ($this->config['import_days'] ?? 30);
        $batchSize = (int) $this->config['batch_size'];

        $this->dedupCutoff = time() - ($dedupDays * 86400);
        $this->importCutoff = $importDays > 0 ? time() - ($importDays * 86400) : null;
        $this->isIncremental = $this->cpuReports->countReports() > 0
            || $this->slowEvents->count() > 0;

        $this->loadMetadata($sources, $dedupDays);

        $files = FileCatalog::enumerate($this->dataDir, $sources);
        $reporter = new ImportProgressReporter($output, $progressInterval);

        $remainingBytes = 0;
        foreach ($files as $file) {
            $size = (int) filesize($file['path']);
            $state = $this->importState->get($file['file_key']);
            $start = $this->resolveStartOffset($file, $state, $size);
            if ($start['skip']) {
                continue;
            }
            $remainingBytes += $size - $start['offset'];
        }
        $reporter->setSessionTotalBytes($remainingBytes);

        if ($remainingBytes > 0) {
            $this->database->beginImportSession();
        }

        try {
            foreach ($files as $file) {
                if ($file['type'] === 'cpu') {
                    $this->importCpuFile($file, $batchSize, $reporter);
                } else {
                    $this->importSlowFile($file, $batchSize, $reporter);
                }
            }
        } finally {
            if ($remainingBytes > 0) {
                $this->database->endImportSession(
                    static function (string $message) use ($output): void {
                        $output->writeln('[post-import] ' . $message);
                    },
                );
            }
        }

        return 0;
    }

    /** @param list<string> $sources */
    private function loadMetadata(array $sources, int $dedupDays): void
    {
        if (!$this->isIncremental) {
            return;
        }

        $since = time() - ($dedupDays * 86400);

        foreach ($sources as $source) {
            $this->maxCpuAt[$source] = $this->cpuReports->maxReportedAt($source);
            $this->cpuDedup += $this->cpuReports->loadDedupKeysSince($source, $since);

            foreach (['slow', 'very_slow'] as $severity) {
                $key = "{$source}:{$severity}";
                $this->maxSlowAt[$key] = $this->slowEvents->maxOccurredAt($source, $severity);
                $this->slowDedup += $this->slowEvents->loadDedupKeysSince($source, $severity, $since);
            }
        }
    }

    /** @param array{file_key: string, source: string, type: string, path: string, severity: ?string, is_rolling: bool} $file */
    private function importSlowFile(array $file, int $batchSize, ImportProgressReporter $reporter): void
    {
        $path = $file['path'];
        $fileKey = $file['file_key'];
        $source = $file['source'];
        $severity = (string) $file['severity'];

        $fileSize = (int) filesize($path);
        $fileMtime = (int) filemtime($path);
        $state = $this->importState->get($fileKey);
        $firstLineHash = LogFileIdentity::readFirstLineHash($path);
        $start = $this->resolveStartOffset($file, $state, $fileSize, $firstLineHash);

        if ($start['skip']) {
            if ($state === null && $this->importCutoff !== null && $start['offset'] >= $fileSize) {
                $reporter->skipFileOutsideWindow($fileKey, (int) ($this->config['import_days'] ?? 30));
            } else {
                $reporter->skipFile($fileKey);
            }

            return;
        }

        $byteOffset = $start['offset'];
        if ($byteOffset >= $fileSize) {
            $reporter->skipFile($fileKey);

            return;
        }

        $maxAtKey = "{$source}:{$severity}";
        $maxAt = $this->maxSlowAt[$maxAtKey] ?? null;
        $this->descriptions->preloadSource($source);
        $parser = new SlowEventParser();

        $reporter->startFile($fileKey, $fileSize, $byteOffset);
        $batch = [];
        $maxOccurredAt = $state !== null && $state['max_occurred_at'] !== null
            ? (int) $state['max_occurred_at']
            : ($maxAt ?? 0);
        $checkpointAt = $byteOffset;

        $this->database->beginTransaction();

        try {
            foreach (LogFileReader::lines(
                $path,
                $byteOffset,
                $this->readMode,
                $this->readChunkBytes,
                $this->maxFileLoadBytes,
            ) as $item) {
                $line = $item['line'];
                $byteOffset = $item['byte_offset'];
                $reporter->tick(strlen($line));

                $parsed = $parser->parseLine($line);
                if ($parsed === null) {
                    $checkpointAt = $this->maybeCheckpoint(
                        $fileKey,
                        $path,
                        $fileSize,
                        $fileMtime,
                        $byteOffset,
                        $checkpointAt,
                        $maxOccurredAt,
                        $firstLineHash,
                    );

                    continue;
                }

                $row = $this->buildSlowRow($source, $severity, $parsed, $maxAt);
                if ($row === null) {
                    $reporter->recordSkipped();
                    $checkpointAt = $this->maybeCheckpoint(
                        $fileKey,
                        $path,
                        $fileSize,
                        $fileMtime,
                        $byteOffset,
                        $checkpointAt,
                        $maxOccurredAt,
                        $firstLineHash,
                    );

                    continue;
                }

                $batch[] = $row;
                $reporter->recordInserted();

                if ($row['occurred_at'] > $maxOccurredAt) {
                    $maxOccurredAt = $row['occurred_at'];
                }

                if (count($batch) >= $batchSize) {
                    $this->flushSlowBatch($source, $batch);
                    $batch = [];
                }

                $checkpointAt = $this->maybeCheckpoint(
                    $fileKey,
                    $path,
                    $fileSize,
                    $fileMtime,
                    $byteOffset,
                    $checkpointAt,
                    $maxOccurredAt,
                    $firstLineHash,
                );
            }

            if ($batch !== []) {
                $this->flushSlowBatch($source, $batch);
            }

            $this->database->commit();
            $this->importState->save(
                $fileKey,
                $path,
                $fileSize,
                $fileMtime,
                $byteOffset,
                $maxOccurredAt,
                $firstLineHash,
            );
        } catch (\Throwable $e) {
            $this->database->rollBack();
            throw $e;
        }

        $reporter->finishFile();
    }

    /**
     * @param list<array{source: string, severity: string, occurred_at: int, execution_ms: int, description: string, detail: string}> $batch
     */
    private function flushSlowBatch(string $source, array $batch): void
    {
        if ($batch === []) {
            return;
        }

        $uniqueDescriptions = [];
        foreach ($batch as $row) {
            $uniqueDescriptions[$row['description']] = true;
        }

        $descriptionIds = $this->descriptions->resolveMany(
            $source,
            array_keys($uniqueDescriptions),
        );

        $rows = [];
        foreach ($batch as $row) {
            $rows[] = [
                'source' => $row['source'],
                'severity' => $row['severity'],
                'occurred_at' => $row['occurred_at'],
                'execution_ms' => $row['execution_ms'],
                'description_id' => $descriptionIds[$row['description']],
                'detail' => $row['detail'],
            ];
        }

        $this->slowEvents->insertBatch($rows);
    }

    /**
     * @param array{occurred_at: int, execution_ms: int, description: string, detail: string} $parsed
     * @return array{source: string, severity: string, occurred_at: int, execution_ms: int, description: string, detail: string}|null
     */
    private function buildSlowRow(string $source, string $severity, array $parsed, ?int $maxAt): ?array
    {
        $occurredAt = $parsed['occurred_at'];

        if ($this->importCutoff !== null && $occurredAt < $this->importCutoff) {
            return null;
        }

        if ($this->isIncremental && $occurredAt < $this->dedupCutoff) {
            return null;
        }

        if (!$this->isIncremental) {
            return [
                'source' => $source,
                'severity' => $severity,
                'occurred_at' => $occurredAt,
                'execution_ms' => $parsed['execution_ms'],
                'description' => $parsed['description'],
                'detail' => $parsed['detail'],
            ];
        }

        $dedupKey = DedupKey::slow(
            $source,
            $severity,
            $occurredAt,
            $parsed['execution_ms'],
            $parsed['description'],
            $parsed['detail'],
        );

        if ($maxAt !== null && $occurredAt > $maxAt) {
            $this->slowDedup[$dedupKey] = true;

            return [
                'source' => $source,
                'severity' => $severity,
                'occurred_at' => $occurredAt,
                'execution_ms' => $parsed['execution_ms'],
                'description' => $parsed['description'],
                'detail' => $parsed['detail'],
            ];
        }

        if (isset($this->slowDedup[$dedupKey])) {
            return null;
        }

        $this->slowDedup[$dedupKey] = true;

        return [
            'source' => $source,
            'severity' => $severity,
            'occurred_at' => $occurredAt,
            'execution_ms' => $parsed['execution_ms'],
            'description' => $parsed['description'],
            'detail' => $parsed['detail'],
        ];
    }

    /** @param array{file_key: string, source: string, type: string, path: string, severity: ?string, is_rolling: bool} $file */
    private function importCpuFile(array $file, int $batchSize, ImportProgressReporter $reporter): void
    {
        $path = $file['path'];
        $fileKey = $file['file_key'];
        $source = $file['source'];

        $fileSize = (int) filesize($path);
        $fileMtime = (int) filemtime($path);
        $state = $this->importState->get($fileKey);
        $firstLineHash = LogFileIdentity::readFirstLineHash($path);
        $start = $this->resolveStartOffset($file, $state, $fileSize, $firstLineHash);

        if ($start['skip']) {
            if ($state === null && $this->importCutoff !== null && $start['offset'] >= $fileSize) {
                $reporter->skipFileOutsideWindow($fileKey, (int) ($this->config['import_days'] ?? 30));
            } else {
                $reporter->skipFile($fileKey);
            }

            return;
        }

        $byteOffset = $start['offset'];
        if ($byteOffset >= $fileSize) {
            $reporter->skipFile($fileKey);

            return;
        }

        $maxAt = $this->maxCpuAt[$source] ?? null;
        $this->descriptions->preloadSource($source);
        $parser = new CpuReportParser();

        $reporter->startFile($fileKey, $fileSize, $byteOffset);
        $statsBatch = [];
        $maxReportedAt = $state !== null && $state['max_occurred_at'] !== null
            ? (int) $state['max_occurred_at']
            : ($maxAt ?? 0);
        $checkpointAt = $byteOffset;

        $currentReportId = null;
        $pendingReportMeta = null;

        $processEvents = function (array $events) use (
            &$statsBatch,
            &$maxReportedAt,
            &$currentReportId,
            &$pendingReportMeta,
            $source,
            $maxAt,
            $batchSize,
            $reporter,
        ): void {
            foreach ($events as $event) {
                $type = $event['type'] ?? '';

                if ($type === 'report_start') {
                    $reportedAt = (int) $event['reported_at'];
                    if ($this->importCutoff !== null && $reportedAt < $this->importCutoff) {
                        $currentReportId = null;
                        $pendingReportMeta = null;

                        continue;
                    }

                    $currentReportId = null;
                    $pendingReportMeta = $event;

                    continue;
                }

                if ($type !== 'stat') {
                    continue;
                }

                $reportedAt = (int) $event['reported_at'];
                if ($this->importCutoff !== null && $reportedAt < $this->importCutoff) {
                    $reporter->recordSkipped();
                    continue;
                }

                if ($pendingReportMeta === null) {
                    $pendingReportMeta = $event;
                }

                if ($currentReportId === null) {
                    $currentReportId = $this->createCpuReport($source, $event);
                }

                $row = $this->buildCpuStatRow($source, $event, $maxAt, $currentReportId);
                if ($row === null) {
                    $reporter->recordSkipped();
                    continue;
                }

                $statsBatch[] = $row;
                $reporter->recordInserted();

                $reportedAt = (int) $event['reported_at'];
                if ($reportedAt > $maxReportedAt) {
                    $maxReportedAt = $reportedAt;
                }

                if (count($statsBatch) >= $batchSize) {
                    $this->cpuReports->insertStatsBatch($statsBatch);
                    $statsBatch = [];
                }
            }
        };

        $this->database->beginTransaction();

        try {
            foreach (LogFileReader::lines(
                $path,
                $byteOffset,
                $this->readMode,
                $this->readChunkBytes,
                $this->maxFileLoadBytes,
            ) as $item) {
                $line = $item['line'];
                $byteOffset = $item['byte_offset'];
                $reporter->tick(strlen($line));
                $processEvents($parser->feedLine($line));

                $checkpointAt = $this->maybeCheckpoint(
                    $fileKey,
                    $path,
                    $fileSize,
                    $fileMtime,
                    $byteOffset,
                    $checkpointAt,
                    $maxReportedAt,
                    $firstLineHash,
                );
            }

            $processEvents($parser->finish());

            if ($statsBatch !== []) {
                $this->cpuReports->insertStatsBatch($statsBatch);
            }

            $this->database->commit();
            $this->importState->save(
                $fileKey,
                $path,
                $fileSize,
                $fileMtime,
                $byteOffset,
                $maxReportedAt,
                $firstLineHash,
            );
        } catch (\Throwable $e) {
            $this->database->rollBack();
            throw $e;
        }

        $reporter->finishFile();
    }

    private function maybeCheckpoint(
        string $fileKey,
        string $path,
        int $fileSize,
        int $fileMtime,
        int $byteOffset,
        int $checkpointAt,
        int $maxTimestamp,
        ?string $firstLineHash,
    ): int {
        if ($byteOffset - $checkpointAt < $this->checkpointBytes) {
            return $checkpointAt;
        }

        $this->database->commit();
        $this->importState->save(
            $fileKey,
            $path,
            $fileSize,
            $fileMtime,
            $byteOffset,
            $maxTimestamp,
            $firstLineHash,
        );
        $this->database->beginTransaction();

        return $byteOffset;
    }

    /** @param array<string, mixed> $meta */
    private function createCpuReport(string $source, array $meta): int
    {
        return $this->cpuReports->insertReport(
            $source,
            (int) $meta['reported_at'],
            isset($meta['thread_id']) ? (int) $meta['thread_id'] : null,
            isset($meta['cpu_usage']) ? (float) $meta['cpu_usage'] : null,
            isset($meta['idle']) ? (float) $meta['idle'] : null,
            isset($meta['other']) ? (float) $meta['other'] : null,
            isset($meta['players_online']) ? (int) $meta['players_online'] : null,
        );
    }

    /**
     * @param array<string, mixed> $event
     * @return array{report_id: int, description_id: int, time_ms: int, calls: int, rel_usage: float, real_usage: float}|null
     */
    private function buildCpuStatRow(string $source, array $event, ?int $maxAt, int $reportId): ?array
    {
        $reportedAt = (int) $event['reported_at'];

        if ($this->importCutoff !== null && $reportedAt < $this->importCutoff) {
            return null;
        }

        if ($this->isIncremental && $reportedAt < $this->dedupCutoff) {
            return null;
        }

        $descriptionId = $this->descriptions->getOrCreate($source, (string) $event['description']);

        if (!$this->isIncremental) {
            return [
                'report_id' => $reportId,
                'description_id' => $descriptionId,
                'time_ms' => (int) $event['time_ms'],
                'calls' => (int) $event['calls'],
                'rel_usage' => (float) $event['rel_usage'],
                'real_usage' => (float) $event['real_usage'],
            ];
        }

        $dedupKey = DedupKey::cpuStat(
            $source,
            $reportedAt,
            $descriptionId,
            (int) $event['time_ms'],
            (int) $event['calls'],
            (string) $event['rel_usage'],
            (string) $event['real_usage'],
        );

        if ($maxAt !== null && $reportedAt > $maxAt) {
            $this->cpuDedup[$dedupKey] = true;

            return [
                'report_id' => $reportId,
                'description_id' => $descriptionId,
                'time_ms' => (int) $event['time_ms'],
                'calls' => (int) $event['calls'],
                'rel_usage' => (float) $event['rel_usage'],
                'real_usage' => (float) $event['real_usage'],
            ];
        }

        if (isset($this->cpuDedup[$dedupKey])) {
            return null;
        }

        $this->cpuDedup[$dedupKey] = true;

        return [
            'report_id' => $reportId,
            'description_id' => $descriptionId,
            'time_ms' => (int) $event['time_ms'],
            'calls' => (int) $event['calls'],
            'rel_usage' => (float) $event['rel_usage'],
            'real_usage' => (float) $event['real_usage'],
        ];
    }

    /**
     * @param array{file_key: string, source: string, type: string, path: string, severity: ?string, is_rolling: bool} $file
     * @param array<string, mixed>|null $state
     * @return array{offset: int, skip: bool}
     */
    private function resolveStartOffset(
        array $file,
        ?array $state,
        int $fileSize,
        ?string $firstLineHash = null,
    ): array {
        if ($fileSize === 0) {
            return ['offset' => 0, 'skip' => true];
        }

        $path = $file['path'];
        $storedFirstLine = $state !== null ? ($state['first_line'] ?? null) : null;
        $byteOffset = $state !== null ? (int) $state['byte_offset'] : 0;

        if ($firstLineHash === null) {
            $firstLineHash = LogFileIdentity::readFirstLineHash($path);
        }

        if ($state !== null && $storedFirstLine !== null && $firstLineHash !== null && $storedFirstLine !== $firstLineHash) {
            return ['offset' => 0, 'skip' => false];
        }

        if ($state !== null && $byteOffset >= $fileSize) {
            return ['offset' => $fileSize, 'skip' => true];
        }

        if ($state !== null && $byteOffset > 0 && $byteOffset < $fileSize) {
            return ['offset' => $byteOffset, 'skip' => false];
        }

        if ($state === null && $file['is_rolling'] && $this->importCutoff !== null) {
            $offset = LogStartOffsetFinder::find($path, $this->importCutoff);
            if ($offset >= $fileSize) {
                return ['offset' => $fileSize, 'skip' => true];
            }

            return ['offset' => $offset, 'skip' => false];
        }

        return ['offset' => 0, 'skip' => false];
    }
}
