<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Doctrine\DBAL\Connection;
use SugarBean;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes orphan rows — rows whose foreign key no longer matches any row in
 * the referenced module's core table — from three kinds of tables:
 *
 * - Custom (_cstm) tables: id_c -> core.id
 * - Per-module audit (_audit) tables: parent_id -> core.id (verified
 *   directly against metadata/audit_templateMetaData.php)
 * - The shared audit_events table: parent_id -> core.id, additionally
 *   scoped by module_name since this one table covers every module
 *   (verified directly against metadata/audit_eventsMetaData.php)
 *
 * Existence is checked via $db->tableExists() rather than a config/vardef
 * flag (e.g. is_AuditEnabled()) — matches how Sugar's own core code guards
 * these same tables (data/SugarBean.php) before touching them, since audit
 * can be toggled independently of whether the table was actually created.
 *
 * Not a stock Administration > Repair action — no equivalent exists in Sugar
 * core. Behavior modeled on esimonetti/toothpaste's
 * local:system:custom-table-orphans-cleanup command (Apache-2.0) for the
 * _cstm part; the _audit/audit_events handling is this package's own
 * extension, reimplemented directly against Sugar's own bean/DBAL APIs.
 * This is a permanent SQL DELETE, not a bean-level soft delete — there's no
 * "restore" for it.
 */
class OrphansCleanupCommand extends AbstractRepairCommand {
    private const BATCH_SIZE = 1000;

    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:repair:orphans-cleanup')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report counts without deleting anything, and skip the confirmation prompt')
            ->setDescription('Delete orphan rows from custom (_cstm), audit (_audit), and audit_events tables — rows with no matching core table record.');
        $this->addConfirmationOption();
        $this->addBackupDirOption();
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln('Dry run — reporting orphan counts only, nothing will be deleted.');
        } else {
            $this->confirmDestructiveAction(
                $input,
                $output,
                'This permanently deletes orphaned custom-table, audit-table, and audit_events rows with no backup.',
            );
        }

        $backupPath = $this->resolveBackupPath($input, $dryRun, 'orphans-cleanup');

        if (null !== $backupPath) {
            $output->writeln(sprintf('Backing up deleted rows to %s', $backupPath));
        }

        global $beanList, $app_list_strings;

        $db = \DBManagerFactory::getInstance();
        $connection = $db->getConnection();
        $fullModuleList = array_merge($beanList, $app_list_strings['moduleList'] ?? []);

        $processedTables = [];
        $total = 0;
        $cstmTablesChecked = 0;
        $auditTablesChecked = 0;

        foreach (array_keys($fullModuleList) as $module) {
            $bean = \BeanFactory::newBean($module);

            if (!$bean instanceof \SugarBean || isset($processedTables[$bean->table_name])) {
                continue;
            }

            $processedTables[$bean->table_name] = true;

            if (method_exists($bean, 'hasCustomFields') && $bean->hasCustomFields()) {
                ++$cstmTablesChecked;
                $total += $this->deleteOrphans(
                    $connection,
                    $bean->get_custom_table_name(),
                    'id_c',
                    'id_c',
                    $bean->table_name,
                    $output,
                    $dryRun,
                    $backupPath,
                );
            }

            $auditTable = $bean->get_audit_table_name();
            if ($db->tableExists($auditTable)) {
                ++$auditTablesChecked;
                $total += $this->deleteOrphans(
                    $connection,
                    $auditTable,
                    'id',
                    'parent_id',
                    $bean->table_name,
                    $output,
                    $dryRun,
                    $backupPath,
                );
            }
        }

        [$auditEventsDeleted, $auditEventsModulesChecked] = $this->cleanupAuditEvents($db, $connection, $output, $dryRun, $backupPath);
        $total += $auditEventsDeleted;

        $output->writeln(sprintf(
            'Checked %d _cstm table(s), %d _audit table(s), and audit_events across %d module(s).',
            $cstmTablesChecked,
            $auditTablesChecked,
            $auditEventsModulesChecked,
        ));
        $output->writeln($dryRun
            ? sprintf('Total orphan rows that would be deleted: %d', $total)
            : sprintf('Total orphan rows deleted: %d', $total));
    }

    /**
     * audit_events is a single table shared by every module (unlike the
     * per-module _audit tables above), so orphan detection has to be scoped
     * per distinct module_name value present in it — the join target table
     * (that module's core table) differs per group.
     *
     * @return array{0: int, 1: int} [rows deleted (or would-delete), distinct module_names checked]
     */
    private function cleanupAuditEvents(\DBManager $db, Connection $connection, OutputInterface $output, bool $dryRun, ?string $backupPath): array
    {
        if (!$db->tableExists('audit_events')) {
            return [0, 0];
        }

        $moduleNames = $connection->createQueryBuilder()
            ->select('DISTINCT module_name')
            ->from('audit_events')
            ->executeQuery()
            ->fetchFirstColumn();

        $total = 0;
        $checked = 0;

        foreach ($moduleNames as $moduleName) {
            $bean = \BeanFactory::newBean($moduleName);

            if (!$bean instanceof \SugarBean) {
                continue;
            }

            ++$checked;
            $total += $this->deleteOrphans(
                $connection,
                'audit_events',
                'id',
                'parent_id',
                $bean->table_name,
                $output,
                $dryRun,
                $backupPath,
                'module_name',
                $moduleName,
            );
        }

        return [$total, $checked];
    }

    /**
     * Deletes rows from $sourceTable, identified by its own $idColumn, whose
     * $joinColumn doesn't match any id in $coreTable — batched at
     * BATCH_SIZE actual rows per delete regardless of how many source rows
     * might share the same $joinColumn value (e.g. many audit rows per
     * parent_id), to keep each delete statement's lock duration bounded.
     *
     * In dry-run mode this runs a single COUNT(*) instead of the batch
     * delete loop — reusing the loop with the DELETE skipped would just
     * return the same LIMIT-ed batch forever, since nothing ever shrinks
     * the result set.
     */
    private function deleteOrphans(
        Connection $connection,
        string $sourceTable,
        string $idColumn,
        string $joinColumn,
        string $coreTable,
        OutputInterface $output,
        bool $dryRun,
        ?string $backupPath,
        ?string $moduleNameColumn = null,
        ?string $moduleName = null,
    ): int {
        if ($dryRun) {
            $countBuilder = $connection->createQueryBuilder()
                ->select('COUNT(*) AS total')
                ->from($sourceTable, 'src')
                ->leftJoin('src', $coreTable, 'core', sprintf('src.%s = core.id', $joinColumn))
                ->where('core.id IS NULL');

            if (null !== $moduleNameColumn) {
                $countBuilder->andWhere(sprintf('src.%s = :moduleName', $moduleNameColumn))
                    ->setParameter('moduleName', $moduleName);
            }

            $count = (int) $countBuilder->executeQuery()->fetchOne();

            if ($count > 0) {
                $output->writeln(sprintf('Would delete %d orphan row(s) from %s', $count, $sourceTable));
            }

            return $count;
        }

        $deletedTotal = 0;

        while (true) {
            $selectBuilder = $connection->createQueryBuilder()
                ->select('src.'.$idColumn)
                ->from($sourceTable, 'src')
                ->leftJoin('src', $coreTable, 'core', sprintf('src.%s = core.id', $joinColumn))
                ->where('core.id IS NULL')
                ->setMaxResults(self::BATCH_SIZE);

            if (null !== $moduleNameColumn) {
                $selectBuilder->andWhere(sprintf('src.%s = :moduleName', $moduleNameColumn))
                    ->setParameter('moduleName', $moduleName);
            }

            $orphanIds = $selectBuilder->executeQuery()->fetchFirstColumn();

            if ([] === $orphanIds) {
                break;
            }

            if (null !== $backupPath) {
                $rowsBuilder = $connection->createQueryBuilder()
                    ->select('src.*')
                    ->from($sourceTable, 'src');
                $rowsBuilder->where($rowsBuilder->expr()->in('src.'.$idColumn, $rowsBuilder->createPositionalParameter(
                    $orphanIds,
                    Connection::PARAM_STR_ARRAY,
                )));
                $rows = array_map(
                    static fn (array $row): array => ['table' => $sourceTable] + $row,
                    $rowsBuilder->executeQuery()->fetchAllAssociative(),
                );
                $this->appendBackupRows($backupPath, $rows);
            }

            $deleteBuilder = $connection->createQueryBuilder();
            $deleteBuilder->delete($sourceTable)
                ->where($deleteBuilder->expr()->in($idColumn, $deleteBuilder->createPositionalParameter(
                    $orphanIds,
                    Connection::PARAM_STR_ARRAY,
                )))
                ->executeStatement();

            $deletedTotal += count($orphanIds);
            $output->writeln(sprintf('Deleted %d orphan row(s) from %s', count($orphanIds), $sourceTable));
        }

        return $deletedTotal;
    }
}
