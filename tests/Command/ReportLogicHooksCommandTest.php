<?php

declare(strict_types=1);
namespace SugarAdminCLI\Tests\Command;

use PHPUnit\Framework\TestCase;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\ReportLogicHooksCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Feeds the fixture custom/modules/TestModule/Ext/LogicHooks/logichooks.ext.php
 * (tests/fixtures/sugar/custom/modules/TestModule/...) — a deliberate
 * same-priority collision on after_save, plus a before_save hook pointing at
 * a file that doesn't exist — and asserts both are flagged.
 */
final class ReportLogicHooksCommandTest extends TestCase {
    private function buildTester(): CommandTester
    {
        $application = new Application();
        $application->add(new ReportLogicHooksCommand());

        return new CommandTester($application->find('amaiza:admin:report:logic-hooks'));
    }

    public function testFlagsPriorityCollision(): void
    {
        $tester = $this->buildTester();

        $tester->execute(['--module' => 'TestModule']);

        self::assertStringContainsString('TestModule.after_save: 2 hooks share priority 1', $tester->getDisplay());
    }

    public function testFlagsMissingFile(): void
    {
        $tester = $this->buildTester();

        $tester->execute(['--module' => 'TestModule']);

        self::assertStringContainsString('file not found: custom/include/DoesNotExist.php', $tester->getDisplay());
    }

    public function testMissingModuleReportsNoFilesFound(): void
    {
        $tester = $this->buildTester();

        $tester->execute(['--module' => 'NoSuchModule']);

        self::assertStringContainsString('No logichooks.ext.php files found.', $tester->getDisplay());
    }
}
