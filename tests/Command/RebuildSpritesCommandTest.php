<?php

declare(strict_types=1);
namespace SugarAdminCLI\Tests\Command;

use PHPUnit\Framework\TestCase;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RebuildSpritesCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RebuildSpritesCommandTest extends TestCase {
    protected function setUp(): void
    {
        $GLOBALS['sugarAdminCliStub_rebuildSpritesCalls'] = [];
    }

    public function testItCallsRebuildSpritesAsAnUpgradeStyleRun(): void
    {
        $application = new Application();
        $application->add(new RebuildSpritesCommand());

        $tester = new CommandTester($application->find('admin:repair:sprites'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([false], $GLOBALS['sugarAdminCliStub_rebuildSpritesCalls']);
    }
}
