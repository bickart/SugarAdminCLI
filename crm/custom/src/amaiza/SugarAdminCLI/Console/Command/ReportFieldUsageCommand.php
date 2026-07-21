<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports what percentage of a module's non-deleted records have each
 * custom field actually populated, flagging fields under --threshold as
 * removal candidates. Not a stock Sugar report — no equivalent exists.
 *
 * Uses the custom (_cstm) table's real DB columns
 * ($db->get_columns(), same ground-truth source ExportRecordsCommand and
 * OrphansCleanupCommand already use) rather than iterating vardef field_defs
 * — a field removed from Studio but never dropped from the DB (exactly the
 * kind of orphaned column this report exists to help find) wouldn't have a
 * vardef entry at all, so vardefs alone would miss it.
 *
 * "Populated" means NOT NULL and not an empty/whitespace-only string —
 * TRIM() on a non-string column (int/date/etc) still works under MySQL's
 * implicit string conversion, but a column whose type genuinely can't be
 * compared this way is skipped with a message rather than failing the
 * whole report.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class ReportFieldUsageCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:report:field-usage')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module to check custom field population for')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Flag fields populated in fewer than this percent of records as removal candidates (default: 1)', '1')
            ->setDescription('Report what percentage of records have each custom field populated, flagging underused fields as removal candidates.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $module = (string) $input->getOption('module');
        $threshold = (float) $input->getOption('threshold');

        if ('' === $module) {
            throw new \RuntimeException('--module is required.');
        }

        $probe = \BeanFactory::newBean($module);

        if (!$probe instanceof \SugarBean) {
            throw new \RuntimeException(sprintf('Unknown module "%s".', $module));
        }

        if (!method_exists($probe, 'hasCustomFields') || !$probe->hasCustomFields()) {
            $output->writeln(sprintf('"%s" has no custom fields table.', $module));

            return;
        }

        $db = \DBManagerFactory::getInstance();
        $connection = $db->getConnection();
        $customTable = $probe->get_custom_table_name();

        $total = (int) $connection->createQueryBuilder()
            ->select('COUNT(*) AS total')
            ->from($probe->table_name)
            ->where('deleted = 0')
            ->executeQuery()
            ->fetchOne();

        if (0 === $total) {
            $output->writeln(sprintf('"%s" has no non-deleted records.', $module));

            return;
        }

        $columns = array_keys($db->get_columns($customTable));
        $flagged = 0;
        $checked = 0;

        foreach ($columns as $column) {
            if ('id_c' === $column) {
                continue;
            }

            ++$checked;

            try {
                $populated = (int) $connection->createQueryBuilder()
                    ->select('COUNT(*) AS populated')
                    ->from($customTable, 'cstm')
                    ->innerJoin('cstm', $probe->table_name, 'core', 'cstm.id_c = core.id')
                    ->where('core.deleted = 0')
                    ->andWhere(sprintf("cstm.%s IS NOT NULL AND TRIM(cstm.%s) != ''", $column, $column))
                    ->executeQuery()
                    ->fetchOne();
            } catch (\Throwable $exception) {
                $output->writeln(sprintf('%s: skipped (%s)', $column, $exception->getMessage()));

                continue;
            }

            $percent = round($populated / $total * 100, 2);
            $isCandidate = $percent < $threshold;

            if ($isCandidate) {
                ++$flagged;
            }

            $output->writeln(sprintf(
                '%s: %.2f%% populated (%d of %d)%s',
                $column,
                $percent,
                $populated,
                $total,
                $isCandidate ? ' — removal candidate' : '',
            ));
        }

        $output->writeln(sprintf('Checked %d custom field(s), %d under %.2f%% populated.', $checked, $flagged, $threshold));
    }
}
