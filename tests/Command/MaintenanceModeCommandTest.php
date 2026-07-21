<?php

declare(strict_types=1);
namespace SugarAdminCLI\Tests\Command;

use PHPUnit\Framework\TestCase;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\MaintenanceOffCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\MaintenanceOnCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MaintenanceModeCommandTest extends TestCase {
    protected function setUp(): void
    {
        \Configurator::reset();
        $GLOBALS['sugar_config']['maintenanceMode'] = false;
    }

    public function testOnSetsMaintenanceModeTrue(): void
    {
        $application = new Application();
        $application->add(new MaintenanceOnCommand());

        $exitCode = new CommandTester($application->find('admin:maintenance:on'))->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($GLOBALS['sugar_config']['maintenanceMode']);
        self::assertSame(1, \Configurator::$handleOverrideCalls);
    }

    public function testOnIsANoOpWhenAlreadyOn(): void
    {
        $GLOBALS['sugar_config']['maintenanceMode'] = true;

        $application = new Application();
        $application->add(new MaintenanceOnCommand());

        $exitCode = new CommandTester($application->find('admin:maintenance:on'))->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(0, \Configurator::$handleOverrideCalls);
    }

    public function testOffSetsMaintenanceModeFalse(): void
    {
        $GLOBALS['sugar_config']['maintenanceMode'] = true;

        $application = new Application();
        $application->add(new MaintenanceOffCommand());

        $exitCode = new CommandTester($application->find('admin:maintenance:off'))->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFalse($GLOBALS['sugar_config']['maintenanceMode']);
        self::assertSame(1, \Configurator::$handleOverrideCalls);
    }
}
