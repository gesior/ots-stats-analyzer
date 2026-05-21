<?php

declare(strict_types=1);

namespace OtsStats\Tests\Unit;

use OtsStats\Service\FileCatalog;
use PHPUnit\Framework\TestCase;

final class FileCatalogTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ots-file-catalog-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testEnumerateFindsStandardAndDailyFiles(): void
    {
        touch($this->tmpDir . '/dispatcher.log');
        touch($this->tmpDir . '/dispatcher_slow.log');
        touch($this->tmpDir . '/dispatcher_2026-05-20.log');
        touch($this->tmpDir . '/dispatcher_slow_2026-05-20.log');
        touch($this->tmpDir . '/lua.log');

        $files = FileCatalog::enumerate($this->tmpDir, ['dispatcher', 'lua']);
        $keys = array_column($files, 'file_key');

        $this->assertContains('dispatcher.log', $keys);
        $this->assertContains('dispatcher_slow.log', $keys);
        $this->assertContains('dispatcher_2026-05-20.log', $keys);
        $this->assertContains('dispatcher_slow_2026-05-20.log', $keys);
        $this->assertContains('lua.log', $keys);
    }

    public function testDailyFilesAreNotMarkedAsRolling(): void
    {
        touch($this->tmpDir . '/dispatcher.log');
        touch($this->tmpDir . '/dispatcher_2026-05-20.log');

        $files = FileCatalog::enumerate($this->tmpDir, ['dispatcher']);
        $rolling = [];
        foreach ($files as $file) {
            $rolling[$file['file_key']] = $file['is_rolling'];
        }

        $this->assertTrue($rolling['dispatcher.log']);
        $this->assertFalse($rolling['dispatcher_2026-05-20.log']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
