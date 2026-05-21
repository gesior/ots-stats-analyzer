<?php

declare(strict_types=1);

namespace OtsStats\Tests\Unit;

use OtsStats\Service\LogFileIdentity;
use PHPUnit\Framework\TestCase;

final class LogFileIdentityTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/ots-log-identity-' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testReadFirstLineHashSkipsEmptyLines(): void
    {
        file_put_contents($this->tmpFile, "\n\r\n[12/3/2026 16:30:56] line\n");

        $hash = LogFileIdentity::readFirstLineHash($this->tmpFile);

        $this->assertSame(hash('sha256', '[12/3/2026 16:30:56] line'), $hash);
    }

    public function testReadFirstLineHashReturnsNullForEmptyFile(): void
    {
        file_put_contents($this->tmpFile, '');

        $this->assertNull(LogFileIdentity::readFirstLineHash($this->tmpFile));
    }

    public function testDifferentFirstLinesProduceDifferentHashes(): void
    {
        file_put_contents($this->tmpFile, "[12/3/2026 16:30:56] first\n");
        $hashA = LogFileIdentity::readFirstLineHash($this->tmpFile);

        file_put_contents($this->tmpFile, "[12/3/2026 16:31:00] second\n");
        $hashB = LogFileIdentity::readFirstLineHash($this->tmpFile);

        $this->assertNotSame($hashA, $hashB);
    }
}
