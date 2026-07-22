<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use SchedulersJob;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

require_once 'modules/Schedulers/_AddJobsHere.php';

/**
 * Runs Sugar's own OOTB "Prune Database" scheduled job (function::pruneDatabase)
 * on demand instead of waiting for its schedule, optionally scoped to specific
 * tables. This is NOT a generic "run OPTIMIZE TABLE on everything" tool (that
 * was toothpaste's esimonetti/toothpaste approach) — pruneDatabase hard-deletes
 * soft-deleted (deleted=1) records older than the configured prune_delay from
 * every table with deleted/date_modified columns (plus matching _cstm/_audit/
 * audit_events rows), then runs $db->optimizeTable() only on tables it actually
 * deleted from. This is real, irreversible deletion of old soft-deleted data —
 * exactly what already happens periodically via the real scheduler, just
 * triggered immediately here.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md. Test the --table option against
 * a non-production instance first: it works by pre-seeding
 * PruneDatabaseService's own serialized resume state (an internal
 * implementation detail of a Sugar core class, not a public API) to skip its
 * normal full-table discovery and process only the given tables — re-verify
 * this still works whenever upgrading the target Sugar version.
 */
class PruneDatabaseCommand extends AbstractRepairCommand {
    private const STATUS_PROCESS = 2;
    private const DEFAULTS = [
        'prune_job_batch_size' => 500,
        'prune_delay' => 24 * 60 * 60 * 1000,
        'prune_job.max_duration' => 20 * 60,
        'prune_job.max_table_retry_count' => 3,
        'prune_job.failure_reset_days' => 7,
        'prune_job.enable_failure_tracking' => true,
        'prune_job.deadlock_retry_attempts' => 3,
        'prune_job.deadlock_retry_delay_ms' => [100, 500, 2000],
    ];

    /**
     * Safety cap on how many times we'll re-invoke pruneDatabase() waiting
     * for the job to reach JOB_STATUS_DONE, in case of an unexpected bug
     * that keeps requeuing the job forever.
     */
    private const MAX_ITERATIONS = 500;

    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:repair:prune-database')
            ->addOption(
                'table',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated table names to prune (default: every table, matching the real scheduled job)',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report counts without deleting anything, and skip the confirmation prompt')
            ->setDescription('Runs the OOTB Prune Database job synchronously to completion, purging old soft-deleted records and optimizing affected tables.');
        $this->addConfirmationOption();
        $this->addBackupDirOption();
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $tableOption = (string) $input->getOption('table');
        $tables = '' !== trim($tableOption)
            ? array_values(array_filter(array_map('trim', explode(',', $tableOption))))
            : null;

        if ((bool) $input->getOption('dry-run')) {
            $output->writeln('Dry run — reporting counts only, nothing will be deleted.');
            $this->runDryRun($tables, $output);

            return;
        }

        $this->confirmDestructiveAction(
            $input,
            $output,
            'This permanently deletes old soft-deleted records with no backup.',
        );

        $backupPath = $this->resolveBackupPath($input, false, 'prune-database');

        if (null !== $backupPath) {
            $output->writeln(sprintf('Backing up rows to %s', $backupPath));
            $this->backupRowsToPrune($tables, $backupPath, $output);
        }

        $job = \BeanFactory::newBean('SchedulersJobs');
        $job->name = 'SugarAdminCLI: Prune Database'.(null !== $tables ? ' ('.implode(',', $tables).')' : '');
        $job->target = 'function::pruneDatabase';
        $job->execute_time = $GLOBALS['timedate']->nowDb();
        $job->assigned_user_id = '1';
        $job->status = \SchedulersJob::JOB_STATUS_QUEUED;
        $job->resolution = \SchedulersJob::JOB_PENDING;

        if (null !== $tables) {
            $job->data = json_encode($this->buildScopedJobState($tables));
        }

        $job->save();

        $iterations = 0;
        while (\SchedulersJob::JOB_STATUS_DONE !== $job->status) {
            if (++$iterations > self::MAX_ITERATIONS) {
                throw new \RuntimeException(sprintf(
                    'Prune Database job did not reach completion after %d iterations (last status: %s).',
                    self::MAX_ITERATIONS,
                    $job->status,
                ));
            }

            pruneDatabase($job);
        }

        $output->writeln($job->message ?? 'Prune Database complete.');

        if (\SchedulersJob::JOB_SUCCESS !== $job->resolution) {
            throw new \RuntimeException(sprintf('Prune Database finished with resolution "%s", not success.', $job->resolution));
        }
    }

    /**
     * PruneDatabaseService has no preview mode of its own, so this
     * reimplements just its row-selection criteria (deleted=1 AND
     * date_modified < threshold, on tables with both columns) as read-only
     * COUNT(*) queries — never touches PruneDatabaseService or creates a
     * SchedulersJob at all.
     *
     * @param list<string>|null $tables
     */
    private function runDryRun(?array $tables, OutputInterface $output): void
    {
        $db = \DBManagerFactory::getInstance();
        $connection = $db->getConnection();
        $tables ??= $db->getTablesArray();
        $threshold = $this->calculateThresholdTime();

        $total = 0;

        foreach ($tables as $table) {
            if (!$db->tableExists($table)) {
                $output->writeln(sprintf('Skipping %s: table does not exist.', $table));

                continue;
            }

            $columns = $db->get_columns($table);
            if (empty($columns['deleted']) || empty($columns['date_modified'])) {
                $output->writeln(sprintf('Skipping %s: missing deleted/date_modified column.', $table));

                continue;
            }

            $count = (int) $connection->createQueryBuilder()
                ->select('COUNT(*) AS total')
                ->from($table)
                ->where('deleted = :deleted')
                ->andWhere('date_modified < :threshold')
                ->setParameter('deleted', 1)
                ->setParameter('threshold', $threshold)
                ->executeQuery()
                ->fetchOne();

            if ($count > 0) {
                $output->writeln(sprintf('Would delete %d row(s) from %s (threshold: %s)', $count, $table, $threshold));
            }

            $total += $count;
        }

        $output->writeln(sprintf('Total rows that would be deleted: %d', $total));
    }

    /**
     * Reuses runDryRun()'s exact row-selection criteria (deleted=1 AND
     * date_modified < threshold, on tables with both columns) but SELECTs
     * full rows instead of COUNT(*), writing them to the backup file before
     * the real pruneDatabase() job runs. A one-time snapshot taken up front
     * rather than backing up each of pruneDatabase()'s own internal delete
     * batches, since its batching is an internal implementation detail this
     * command already treats as a black box.
     *
     * @param list<string>|null $tables
     */
    private function backupRowsToPrune(?array $tables, string $backupPath, OutputInterface $output): void
    {
        $db = \DBManagerFactory::getInstance();
        $connection = $db->getConnection();
        $tables ??= $db->getTablesArray();
        $threshold = $this->calculateThresholdTime();

        foreach ($tables as $table) {
            if (!$db->tableExists($table)) {
                continue;
            }

            $columns = $db->get_columns($table);
            if (empty($columns['deleted']) || empty($columns['date_modified'])) {
                continue;
            }

            $rows = $connection->createQueryBuilder()
                ->select('*')
                ->from($table)
                ->where('deleted = :deleted')
                ->andWhere('date_modified < :threshold')
                ->setParameter('deleted', 1)
                ->setParameter('threshold', $threshold)
                ->executeQuery()
                ->fetchAllAssociative();

            if ([] === $rows) {
                continue;
            }

            $this->appendBackupRows($backupPath, array_map(
                static fn (array $row): array => ['table' => $table] + $row,
                $rows,
            ));
            $output->writeln(sprintf('Backed up %d row(s) from %s', count($rows), $table));
        }
    }

    private function calculateThresholdTime(): string
    {
        $config = \SugarConfig::getInstance();
        $pruneDelayMs = (int) $config->get('prune_delay', self::DEFAULTS['prune_delay']);
        $pruneDelaySeconds = max(0, (int) round($pruneDelayMs / 1000));

        return new \DateTime()
            ->sub(new \DateInterval('PT'.$pruneDelaySeconds.'S'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Pre-seeds PruneDatabaseService's serialized resume state directly into
     * STATUS_PROCESS, so its own doInit() (which unconditionally scans every
     * table via $db->getTablesArray()) never runs — only our given tables get
     * processed. Values otherwise mirror doInit()'s own config reads/defaults
     * exactly, since every property here is required for doProcess() to run
     * correctly (untyped private properties left unset would just be null).
     *
     * @param list<string> $tables
     *
     * @return array<string, mixed>
     */
    private function buildScopedJobState(array $tables): array
    {
        $config = \SugarConfig::getInstance();
        $pruneDelayMs = (int) $config->get('prune_delay', self::DEFAULTS['prune_delay']);
        $pruneDelaySeconds = max(0, (int) round($pruneDelayMs / 1000));

        return [
            'status' => self::STATUS_PROCESS,
            'current_table_index' => 0,
            'total_tables' => count($tables),
            'processed_tables' => 0,
            'failed_tables' => [],
            'failure_counts' => [],
            'last_failure_timestamps' => [],
            'threshold_time' => $this->calculateThresholdTime(),
            'batch_size' => (int) $config->get('prune_job_batch_size', self::DEFAULTS['prune_job_batch_size']),
            'prune_delay_seconds' => $pruneDelaySeconds,
            'max_duration' => (int) $config->get('prune_job.max_duration', self::DEFAULTS['prune_job.max_duration']),
            'max_table_retry_count' => (int) $config->get('prune_job.max_table_retry_count', self::DEFAULTS['prune_job.max_table_retry_count']),
            'failure_reset_days' => (int) $config->get('prune_job.failure_reset_days', self::DEFAULTS['prune_job.failure_reset_days']),
            'enable_failure_tracking' => (bool) $config->get('prune_job.enable_failure_tracking', self::DEFAULTS['prune_job.enable_failure_tracking']),
            'tables_to_process' => $tables,
            'deadlock_retry_attempts' => (int) $config->get('prune_job.deadlock_retry_attempts', self::DEFAULTS['prune_job.deadlock_retry_attempts']),
            'deadlock_retry_delays' => $config->get('prune_job.deadlock_retry_delay_ms', self::DEFAULTS['prune_job.deadlock_retry_delay_ms']),
        ];
    }
}
