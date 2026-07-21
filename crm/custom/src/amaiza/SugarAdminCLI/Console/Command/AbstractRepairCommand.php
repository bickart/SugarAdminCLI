<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Sugarcrm\Sugarcrm\Console\CommandRegistry\Mode\InstanceModeInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared shape for every admin:repair:* command: buffer whatever HTML the
 * underlying stock Sugar file echoes (it was written for a browser, not a
 * console), report success/failure via SymfonyStyle, and translate any
 * exception into a non-zero exit code instead of a raw stack trace.
 */
abstract class AbstractRepairCommand extends Command implements InstanceModeInterface {
    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title((string) $this->getDescription());

        ob_start();

        try {
            $this->repair($input, $output);
        } catch (\Throwable $exception) {
            ob_end_clean();
            $io->error(sprintf('%s failed: %s', static::class, $exception->getMessage()));

            return Command::FAILURE;
        }

        $buffered = trim(strip_tags((string) ob_get_clean()));

        if ($output->isVerbose() && '' !== $buffered) {
            $io->text($buffered);
        }

        $io->success('Complete.');

        return Command::SUCCESS;
    }

    /**
     * Perform the actual repair. Implementations should pre-seed whatever
     * $_REQUEST/$_POST/global state the target stock Sugar file expects,
     * then require that file (or call the specific function/method it
     * exposes) directly — never reimplement Sugar's own repair logic.
     */
    abstract protected function repair(InputInterface $input, OutputInterface $output): void;

    /**
     * Registers the --yes/-y flag a destructive command needs alongside
     * confirmDestructiveAction(). Call from configure().
     */
    protected function addConfirmationOption(): static
    {
        return $this->addOption(
            'yes',
            'y',
            InputOption::VALUE_NONE,
            'Skip the confirmation prompt (required for non-interactive/CI use, since this command modifies data irreversibly)',
        );
    }

    /**
     * Gates an irreversible action behind explicit confirmation: --yes/-y
     * skips the prompt outright (for scripts/CI); otherwise, in an
     * interactive terminal, asks and proceeds only on an explicit yes;
     * otherwise (non-interactive, no --yes) fails clearly rather than
     * silently defaulting to "no". Requires addConfirmationOption() to have
     * been called in configure().
     */
    protected function confirmDestructiveAction(InputInterface $input, OutputInterface $output, string $message): void
    {
        if (true === $input->getOption('yes')) {
            return;
        }

        if (!$input->isInteractive()) {
            throw new \RuntimeException(sprintf('%s Pass --yes (-y) to proceed non-interactively.', $message));
        }

        $io = new SymfonyStyle($input, $output);

        if (!$io->confirm($message.' Continue?', false)) {
            throw new \RuntimeException('Aborted — confirmation declined.');
        }
    }
}
