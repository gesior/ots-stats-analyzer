<?php

declare(strict_types=1);

namespace OtsStats\Tests\Unit;

use OtsStats\Service\ImportProgressReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class ImportProgressReporterTest extends TestCase
{
    public function testFormatBytesAndDuration(): void
    {
        $this->assertSame('1.00 GiB', ImportProgressReporter::formatBytes(1024 * 1024 * 1024));
        $this->assertSame('01:05:07', ImportProgressReporter::formatDuration(3907));
        $this->assertSame('1.2M', ImportProgressReporter::formatCount(1_200_000));
    }

    public function testProgressLineContainsEta(): void
    {
        $output = new BufferedOutput();
        $reporter = new ImportProgressReporter($output, 0.0);
        $reporter->setSessionTotalBytes(1000);
        $reporter->startFile('test:slow', 1000, 0);

        for ($i = 0; $i < 10; ++$i) {
            $reporter->tick(100);
            $reporter->recordInserted();
        }

        $reporter->finishFile();
        $text = $output->fetch();
        $this->assertStringContainsString('DONE', $text);
        $this->assertStringContainsString('test:slow', $text);
    }
}
