<?php

declare(strict_types=1);
namespace SugarAdminCLI\Tests\Command;

use PHPUnit\Framework\TestCase;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\AbstractRepairCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers AbstractRepairCommand::addConfirmationOption()/confirmDestructiveAction()
 * directly via a minimal test-double command, since neither real command that
 * uses it (OrphansCleanupCommand, PruneDatabaseCommand) is fully stubbed yet.
 */
final class ConfirmDestructiveActionTest extends TestCase {
    private function buildTester(): CommandTester
    {
        $command = new class extends AbstractRepairCommand {
            public static int $repairRuns = 0;

            protected function configure(): void
            {
                $this->setName('test:destructive')->setDescription('Test command');
                $this->addConfirmationOption();
            }

            protected function repair(InputInterface $input, OutputInterface $output): void
            {
                $this->confirmDestructiveAction($input, $output, 'This deletes everything.');
                ++self::$repairRuns;
            }
        };
        $command::$repairRuns = 0;

        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('test:destructive'));
    }

    public function testYesFlagSkipsPromptAndProceeds(): void
    {
        $tester = $this->buildTester();

        $exitCode = $tester->execute(['--yes' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testNonInteractiveWithoutYesFailsClearly(): void
    {
        $tester = $this->buildTester();

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--yes', $tester->getDisplay());
    }

    public function testInteractiveDeclineAborts(): void
    {
        $tester = $this->buildTester();
        $tester->setInputs(['no']);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Aborted', $tester->getDisplay());
    }

    public function testInteractiveConfirmProceeds(): void
    {
        $tester = $this->buildTester();
        $tester->setInputs(['yes']);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }
}
