<?php

declare(strict_types=1);
namespace SugarAdminCLI\Tests\Command;

use PHPUnit\Framework\TestCase;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RebuildRelationshipsCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RebuildRelationshipsCommandTest extends TestCase {
    protected function setUp(): void
    {
        \SugarRelationshipFactory::reset();
    }

    public function testItRebuildsTheRelationshipCache(): void
    {
        $application = new Application();
        $application->add(new RebuildRelationshipsCommand());

        $tester = new CommandTester($application->find('admin:repair:relationships'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(1, \SugarRelationshipFactory::$rebuildCacheCalls);
    }
}
