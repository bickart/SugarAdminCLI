<?php

declare(strict_types=1);
namespace SugarAdminCLI\Tests\Command;

use PHPUnit\Framework\TestCase;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RepairRolesCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RepairRolesCommandTest extends TestCase {
    protected function setUp(): void
    {
        $GLOBALS['sugarAdminCliTestCalls']['install_actions'] = [];
        unset($_REQUEST['upgradeWizard']);
    }

    public function testItRequiresInstallActionsInSilentMode(): void
    {
        $application = new Application();
        $application->add(new RepairRolesCommand());

        $tester = new CommandTester($application->find('amaiza:admin:repair:roles'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(1, $GLOBALS['sugarAdminCliTestCalls']['install_actions']);
        self::assertSame('silent', $GLOBALS['sugarAdminCliTestCalls']['install_actions'][0]['upgradeWizard']);
    }
}
