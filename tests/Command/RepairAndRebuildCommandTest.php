<?php

declare(strict_types=1);
namespace SugarAdminCLI\Tests\Command;

use PHPUnit\Framework\TestCase;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RepairAndRebuildCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RepairAndRebuildCommandTest extends TestCase {
    protected function setUp(): void
    {
        \RepairAndClear::reset();
        \LanguageManager::reset();
    }

    public function testItCallsRepairAndClearAllWithAllModules(): void
    {
        $application = new Application();
        $application->add(new RepairAndRebuildCommand());

        $tester = new CommandTester($application->find('amaiza:admin:repair:qrr'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(1, \RepairAndClear::$repairAndClearAllCalls);
        self::assertSame(['clearAll'], \RepairAndClear::$repairAndClearAllCalls[0]['actions']);
        self::assertTrue(\RepairAndClear::$repairAndClearAllCalls[0]['autoexecute']);
        self::assertFalse(\RepairAndClear::$repairAndClearAllCalls[0]['show_output']);
        self::assertSame(1, \LanguageManager::$removeJSLanguageFilesCalls);
        self::assertSame(1, \LanguageManager::$clearLanguageCacheCalls);
    }

    public function testAdminQrrAliasResolvesToTheSameCommand(): void
    {
        $application = new Application();
        $application->add(new RepairAndRebuildCommand());

        self::assertSame(
            $application->find('amaiza:admin:repair:qrr'),
            $application->find('amaiza:admin:qrr'),
        );
    }
}
