<?php

declare(strict_types=1);
namespace SugarAdminCLI\Tests\Command;

use PHPUnit\Framework\TestCase;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\AbstractRepairCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Covers AbstractRepairCommand's backup-writer mechanism
 * (appendBackupRows()/defaultBackupPath()/resolveBackupPath()) directly via
 * a minimal test-double command — pure file I/O and array handling, needs
 * no Sugar bean/DB stubs at all, unlike the commands that actually call it
 * (OrphansCleanupCommand, PruneDatabaseCommand, OrphanedParentCleanupCommand,
 * ExportRecordsCommand).
 */
final class BackupWriterTest extends TestCase {
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/sugaradmincli-test-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    private function buildCommand(): AbstractRepairCommand
    {
        return new class extends AbstractRepairCommand {
            protected function configure(): void
            {
                $this->setName('test:backup-writer');
                $this->addBackupDirOption();
            }

            protected function repair(InputInterface $input, OutputInterface $output): void
            {
            }

            /**
             * @param list<array<string, mixed>> $rows
             */
            public function callAppendBackupRows(string $path, array $rows): void
            {
                $this->appendBackupRows($path, $rows);
            }

            public function callResolveBackupPath(InputInterface $input, bool $dryRun, string $label): ?string
            {
                return $this->resolveBackupPath($input, $dryRun, $label);
            }
        };
    }

    public function testAppendBackupRowsWritesValidCompleteJsonLines(): void
    {
        $command = $this->buildCommand();
        $path = $this->tempDir.'/rows.jsonl';

        $command->callAppendBackupRows($path, [
            ['id' => '1', 'name' => 'First', 'nested' => ['a' => 1]],
            ['id' => '2', 'name' => 'Second'],
        ]);

        self::assertFileExists($path);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
        self::assertCount(2, $lines);
        self::assertSame(['id' => '1', 'name' => 'First', 'nested' => ['a' => 1]], json_decode($lines[0], true));
        self::assertSame(['id' => '2', 'name' => 'Second'], json_decode($lines[1], true));
    }

    public function testAppendBackupRowsAppendsAcrossCalls(): void
    {
        $command = $this->buildCommand();
        $path = $this->tempDir.'/rows.jsonl';

        $command->callAppendBackupRows($path, [['id' => '1']]);
        $command->callAppendBackupRows($path, [['id' => '2']]);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
        self::assertCount(2, $lines);
    }

    public function testAppendBackupRowsWithEmptyArrayDoesNotCreateFile(): void
    {
        $command = $this->buildCommand();
        $path = $this->tempDir.'/rows.jsonl';

        $command->callAppendBackupRows($path, []);

        self::assertFileDoesNotExist($path);
    }

    public function testAppendBackupRowsCreatesMissingNestedDirectory(): void
    {
        $command = $this->buildCommand();
        $path = $this->tempDir.'/nested/dir/rows.jsonl';

        $command->callAppendBackupRows($path, [['id' => '1']]);

        self::assertFileExists($path);
    }

    public function testResolveBackupPathReturnsNullWhenBackupDirNotPassed(): void
    {
        $command = $this->buildCommand();
        $input = new ArrayInput([], $command->getDefinition());

        self::assertNull($command->callResolveBackupPath($input, false, 'label'));
    }

    public function testResolveBackupPathReturnsNullOnDryRunEvenIfPassed(): void
    {
        $command = $this->buildCommand();
        $input = new ArrayInput(['--backup-dir' => $this->tempDir], $command->getDefinition());

        self::assertNull($command->callResolveBackupPath($input, true, 'label'));
    }

    public function testResolveBackupPathReturnsTimestampedPathWhenPassed(): void
    {
        $command = $this->buildCommand();
        $input = new ArrayInput(['--backup-dir' => $this->tempDir], $command->getDefinition());

        $path = $command->callResolveBackupPath($input, false, 'label');

        self::assertNotNull($path);
        self::assertStringStartsWith($this->tempDir.'/label-', $path);
        self::assertStringEndsWith('.jsonl', $path);
    }
}
