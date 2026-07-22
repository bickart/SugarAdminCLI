<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Sugarcrm\Sugarcrm\Console\CommandRegistry\Mode\InstanceModeInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared shape for every amaiza:admin:repair:* command: buffer whatever HTML the
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

    /**
     * A stronger gate than confirmDestructiveAction(): there is no --yes
     * bypass at all, so this can only ever be satisfied by someone present
     * at an interactive terminal. For actions risky or slow enough (e.g. a
     * multi-table DDL rebuild that can run for a long time against
     * production data) that skipping the prompt via automation should never
     * be possible, not just discouraged.
     */
    protected function requireInteractiveConfirmation(InputInterface $input, OutputInterface $output, string $message): void
    {
        if (!$input->isInteractive()) {
            throw new \RuntimeException(sprintf('%s This command must be run interactively — there is no --yes bypass.', $message));
        }

        $io = new SymfonyStyle($input, $output);

        if (!$io->confirm($message.' Continue?', false)) {
            throw new \RuntimeException('Aborted — confirmation declined.');
        }
    }

    /**
     * Like confirmDestructiveAction(), but for a sub-part of a larger run
     * where declining should skip just that part rather than abort the
     * whole command (e.g. one distinct group of records within a batch
     * operation that need their own, more specific consent). --yes still
     * skips the prompt outright; non-interactive without --yes still fails
     * clearly rather than silently assuming yes or no.
     */
    protected function confirmOrSkip(InputInterface $input, OutputInterface $output, string $message): bool
    {
        if (true === $input->getOption('yes')) {
            return true;
        }

        if (!$input->isInteractive()) {
            throw new \RuntimeException(sprintf('%s Pass --yes (-y) to proceed non-interactively.', $message));
        }

        return new SymfonyStyle($input, $output)->confirm($message.' Continue?', false);
    }

    /**
     * Registers --backup-dir for a command that can back up rows before
     * deleting them. Call from configure().
     */
    protected function addBackupDirOption(): static
    {
        return $this->addOption(
            'backup-dir',
            null,
            InputOption::VALUE_REQUIRED,
            'Directory to write a JSON Lines backup of affected rows to before deleting (default: ./sugaradmincli-backups/, omit --backup-dir entirely to skip backing up)',
        );
    }

    /**
     * Builds a default backup file path under $backupDir, timestamped so
     * repeated runs (and multiple tables/modules within one run) don't
     * collide. JSON Lines (.jsonl), not a single JSON array, so a run that's
     * interrupted partway still leaves every row written so far valid and
     * readable, and multiple call sites can append to the same file over
     * the course of one command run without re-reading it first.
     */
    protected function defaultBackupPath(string $backupDir, string $label): string
    {
        return sprintf('%s/%s-%s.jsonl', rtrim($backupDir, '/'), $label, date('Ymd_His'));
    }

    /**
     * Resolves the effective backup path for a command run: null (skip
     * backing up entirely) if --backup-dir wasn't passed or this is a
     * dry-run (nothing will actually be deleted, so there's nothing to back
     * up), otherwise a timestamped file path under the given directory.
     */
    protected function resolveBackupPath(InputInterface $input, bool $dryRun, string $label): ?string
    {
        if ($dryRun) {
            return null;
        }

        $backupDir = $input->getOption('backup-dir');

        if (null === $backupDir || '' === trim((string) $backupDir)) {
            return null;
        }

        return $this->defaultBackupPath((string) $backupDir, $label);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    protected function appendBackupRows(string $path, array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create backup directory: %s', $dir));
        }

        $handle = fopen($path, 'a');

        if (false === $handle) {
            throw new \RuntimeException(sprintf('Unable to open backup file: %s', $path));
        }

        foreach ($rows as $row) {
            fwrite($handle, json_encode($row, \JSON_UNESCAPED_SLASHES)."\n");
        }

        fclose($handle);
    }
}
