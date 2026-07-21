<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Implements Sugar Support's own recommended SugarBPM (pmse_*) table
 * cleanup procedure (provided directly to this instance's admin,
 * 2025-07-09): a CREATE => RENAME => INSERT dance per table, which
 * write-locks each table only for the instant of the RENAME (new rows queue
 * instead of being lost mid-copy) rather than locking for the whole copy.
 *
 * Tables are processed pmse_bpm_flow, pmse_bpm_form_action, pmse_bpm_thread,
 * then pmse_inbox LAST — deliberately, since the first 3 batches all query
 * the *original*, not-yet-renamed pmse_inbox to decide what to keep, so
 * pmse_inbox itself must be the last table touched.
 *
 * Retains: every row for a case newer than --before or currently
 * cas_status = 'IN PROGRESS', plus (pmse_bpm_flow only) every cas_index = 1
 * first-run row belonging to a process definition with a First
 * Update/Updated start event — per Sugar's own defect #93985, purging a
 * first-run row for one of those processes can spuriously re-trigger it, so
 * those are never pruned regardless of age.
 *
 * Old data is renamed to {table}_delete, never dropped by the main run —
 * recoverable by hand (swap the names back) until a separate, explicitly
 * confirmed --drop-renamed-tables run removes them for good, matching
 * Sugar Support's own "only run DROP after validating the copy" guidance.
 *
 * Both actions require interactive confirmation with no --yes bypass
 * (requireInteractiveConfirmation(), not confirmDestructiveAction()) — the
 * prune can run for a long time against production-sized pmse_* tables, and
 * neither action is something that should ever be safe to trigger
 * unattended from a script or cron job.
 *
 * The prune also pauses every currently-Active Schedulers record (setting
 * status = 'Inactive') before touching any table, and restores exactly
 * those records to Active afterward — in a finally block, so a failure
 * partway through still resumes them — so no scheduled job's cron.php
 * invocation can race the RENAME/INSERT sequence while it's in progress.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md. This runs raw DDL (CREATE
 * TABLE, RENAME TABLE, DROP TABLE) directly via Doctrine DBAL, bypassing the
 * bean/ORM layer entirely — test against a disposable, non-production
 * instance first, and keep a real database backup regardless of
 * --backup-dir (that only covers the 3 destructive bean-level commands).
 */
class PruneBpmTablesCommand extends AbstractRepairCommand {
    /**
     * @var list<string>
     */
    private const TABLES = ['pmse_bpm_flow', 'pmse_bpm_form_action', 'pmse_bpm_thread', 'pmse_inbox'];

    protected function configure(): void
    {
        $this
            ->setName('admin:repair:prune-bpm-tables')
            ->addOption(
                'before',
                null,
                InputOption::VALUE_REQUIRED,
                'Cutoff date (YYYY-MM-DD) — cases older than this are pruned unless IN PROGRESS or a protected first-run row',
            )
            ->addOption(
                'drop-renamed-tables',
                null,
                InputOption::VALUE_NONE,
                'Instead of pruning, permanently DROP the *_delete tables left by a prior run (only after you\'ve validated the copy)',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report row counts that would be kept/pruned (or dropped) without touching any table, and skip the confirmation prompt')
            ->setDescription("Prune SugarBPM (pmse_*) tables using Sugar Support's CREATE=>RENAME=>INSERT procedure, keeping recent/in-progress/protected rows.");
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $db = \DBManagerFactory::getInstance();
        $connection = $db->getConnection();
        $dryRun = (bool) $input->getOption('dry-run');

        if ((bool) $input->getOption('drop-renamed-tables')) {
            $this->dropRenamedTables($db, $connection, $input, $output, $dryRun);

            return;
        }

        $before = (string) $input->getOption('before');

        if ('' === $before || false === \DateTime::createFromFormat('Y-m-d', $before)) {
            throw new \RuntimeException('--before=YYYY-MM-DD is required (or pass --drop-renamed-tables to remove leftover *_delete tables from a prior run).');
        }

        foreach (self::TABLES as $table) {
            foreach (['_temp', '_delete'] as $suffix) {
                if ($db->tableExists($table.$suffix)) {
                    throw new \RuntimeException(sprintf(
                        'Table "%s%s" already exists — a prior run may not have completed cleanly. Resolve manually, or run --drop-renamed-tables to remove leftover *_delete tables, before continuing.',
                        $table,
                        $suffix,
                    ));
                }
            }
        }

        if ($dryRun) {
            $output->writeln('Dry run — reporting row counts only, nothing will be touched.');
            $this->reportDryRun($connection, $before, $output);

            return;
        }

        $this->requireInteractiveConfirmation(
            $input,
            $output,
            'This renames each pmse_* table and rebuilds it containing only recent/in-progress/protected rows — it can take a long time against production-sized tables. Old data is preserved as *_delete tables (not dropped) until a separate --drop-renamed-tables run.',
        );

        $pausedSchedulerIds = $this->pauseSchedulers($connection, $output);

        try {
            foreach (self::TABLES as $table) {
                $this->pruneTable($connection, $table, $before, $output);
            }
        } finally {
            $this->resumeSchedulers($connection, $pausedSchedulerIds, $output);
        }

        $output->writeln('Done. Old data preserved in *_delete tables — verify row counts, then run --drop-renamed-tables to remove them for good.');
    }

    /**
     * Sets every currently-Active Schedulers record to Inactive so no
     * scheduled job can race the RENAME/INSERT sequence below, and returns
     * their ids so resumeSchedulers() can restore exactly those records
     * (not any that were already Inactive beforehand) once the prune
     * finishes or fails.
     *
     * @return list<string>
     */
    private function pauseSchedulers(Connection $connection, OutputInterface $output): array
    {
        $activeIds = $connection->createQueryBuilder()
            ->select('id')
            ->from('schedulers')
            ->where('status = :status')
            ->andWhere('deleted = 0')
            ->setParameter('status', 'Active')
            ->executeQuery()
            ->fetchFirstColumn();

        if ([] === $activeIds) {
            $output->writeln('No active Schedulers to pause.');

            return [];
        }

        $this->setSchedulersStatus($connection, $activeIds, 'Inactive');
        $output->writeln(sprintf('Paused %d active Scheduler(s) for the duration of this run.', count($activeIds)));

        return $activeIds;
    }

    /**
     * @param list<string> $ids
     */
    private function resumeSchedulers(Connection $connection, array $ids, OutputInterface $output): void
    {
        if ([] === $ids) {
            return;
        }

        $this->setSchedulersStatus($connection, $ids, 'Active');
        $output->writeln(sprintf('Resumed %d Scheduler(s).', count($ids)));
    }

    /**
     * @param list<string> $ids
     */
    private function setSchedulersStatus(Connection $connection, array $ids, string $status): void
    {
        $builder = $connection->createQueryBuilder();
        $builder->update('schedulers')
            ->set('status', $builder->createPositionalParameter($status))
            ->where($builder->expr()->in('id', $builder->createPositionalParameter($ids, Connection::PARAM_STR_ARRAY)))
            ->executeStatement();
    }

    private function pruneTable(Connection $connection, string $table, string $before, OutputInterface $output): void
    {
        $beforeCount = (int) $connection->executeQuery(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchOne();

        $connection->executeStatement(sprintf('CREATE TABLE %s_temp LIKE %s', $table, $table));
        $connection->executeStatement(sprintf('RENAME TABLE %1$s TO %1$s_delete, %1$s_temp TO %1$s', $table));
        $connection->executeStatement($this->insertSql($table), ['before' => $before]);

        $afterCount = (int) $connection->executeQuery(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchOne();

        $output->writeln(sprintf(
            '%s: kept %d of %d row(s) (%d moved into %s_delete).',
            $table,
            $afterCount,
            $beforeCount,
            $beforeCount - $afterCount,
            $table,
        ));
    }

    private function reportDryRun(Connection $connection, string $before, OutputInterface $output): void
    {
        foreach (self::TABLES as $table) {
            $total = (int) $connection->executeQuery(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchOne();
            $retained = (int) $connection->executeQuery($this->retainCountSql($table), ['before' => $before])->fetchOne();

            $output->writeln(sprintf('%s: would keep %d of %d row(s), %d would be pruned.', $table, $retained, $total, $total - $retained));
        }
    }

    private function dropRenamedTables(\DBManager $db, Connection $connection, InputInterface $input, OutputInterface $output, bool $dryRun): void
    {
        $existing = array_values(array_filter(
            array_map(static fn (string $table): string => $table.'_delete', self::TABLES),
            static fn (string $table): bool => $db->tableExists($table),
        ));

        if ([] === $existing) {
            $output->writeln('No *_delete tables found — nothing to drop.');

            return;
        }

        if ($dryRun) {
            $output->writeln(sprintf('Dry run — would permanently drop: %s', implode(', ', $existing)));

            return;
        }

        $this->requireInteractiveConfirmation($input, $output, sprintf('This PERMANENTLY drops: %s. There is no undo.', implode(', ', $existing)));

        foreach ($existing as $table) {
            $connection->executeStatement(sprintf('DROP TABLE %s', $table));
            $output->writeln(sprintf('Dropped %s.', $table));
        }
    }

    /**
     * Exact SELECT COUNT(*) equivalent of insertSql()'s WHERE/JOIN criteria
     * for a given table, used only for --dry-run reporting — kept separate
     * from (but matched to) insertSql() since a COUNT(*) can't reuse an
     * "INSERT INTO ... SELECT f.*" statement's shape directly.
     */
    private function retainCountSql(string $table): string
    {
        return match ($table) {
            'pmse_bpm_flow' => "SELECT COUNT(*) FROM pmse_bpm_flow f WHERE f.cas_id IN (SELECT cas_id FROM pmse_inbox WHERE date_entered > :before OR cas_status = 'IN PROGRESS') OR (f.cas_index = 1 AND f.pro_id IN (SELECT DISTINCT pro_id FROM pmse_bpm_event_definition WHERE evn_type = 'START' AND evn_params IN ('newfirstupdated', 'updated')))",
            'pmse_bpm_form_action', 'pmse_bpm_thread' => sprintf(
                "SELECT COUNT(*) FROM %s f INNER JOIN pmse_inbox i ON i.cas_id = f.cas_id WHERE i.date_entered > :before OR i.cas_status = 'IN PROGRESS'",
                $table,
            ),
            'pmse_inbox' => "SELECT COUNT(*) FROM pmse_inbox WHERE date_entered > :before OR cas_status = 'IN PROGRESS'",
            default => throw new \LogicException('Unknown table: '.$table),
        };
    }

    /**
     * Verbatim (aside from the parameterized cutoff date) Sugar Support's
     * own recommended queries — deliberately not "improved" or restructured,
     * since these are the exact queries Support already validated for this
     * procedure.
     */
    private function insertSql(string $table): string
    {
        return match ($table) {
            'pmse_bpm_flow' => "INSERT INTO pmse_bpm_flow SELECT f.* FROM pmse_bpm_flow_delete f WHERE f.cas_id IN (SELECT cas_id FROM pmse_inbox WHERE date_entered > :before OR cas_status = 'IN PROGRESS') OR (f.cas_index = 1 AND f.pro_id IN (SELECT DISTINCT pro_id FROM pmse_bpm_event_definition WHERE evn_type = 'START' AND evn_params IN ('newfirstupdated', 'updated')))",
            'pmse_bpm_form_action' => "INSERT INTO pmse_bpm_form_action SELECT f.* FROM pmse_bpm_form_action_delete f INNER JOIN pmse_inbox i ON i.cas_id = f.cas_id WHERE i.date_entered > :before OR i.cas_status = 'IN PROGRESS'",
            'pmse_bpm_thread' => "INSERT INTO pmse_bpm_thread SELECT f.* FROM pmse_bpm_thread_delete f INNER JOIN pmse_inbox i ON i.cas_id = f.cas_id WHERE i.date_entered > :before OR i.cas_status = 'IN PROGRESS'",
            'pmse_inbox' => "INSERT INTO pmse_inbox SELECT * FROM pmse_inbox_delete WHERE date_entered > :before OR cas_status = 'IN PROGRESS'",
            default => throw new \LogicException('Unknown table: '.$table),
        };
    }
}
