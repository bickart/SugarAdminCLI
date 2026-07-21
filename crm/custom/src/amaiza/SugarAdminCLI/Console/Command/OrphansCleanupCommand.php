<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes orphan rows from custom (_cstm) tables — rows whose id_c no longer
 * matches any row in the module's core table.
 *
 * Not a stock Administration > Repair action — no equivalent exists in Sugar
 * core. Behavior modeled on esimonetti/toothpaste's
 * local:system:custom-table-orphans-cleanup command (Apache-2.0),
 * reimplemented here directly against Sugar's own bean/DBAL APIs rather than
 * reusing that project's code. This is a permanent SQL DELETE, not a
 * bean-level soft delete — there's no "restore" for it.
 */
class OrphansCleanupCommand extends AbstractRepairCommand {
    private const BATCH_SIZE = 1000;

    protected function configure(): void
    {
        $this
            ->setName('admin:repair:orphans-cleanup')
            ->setDescription('Delete orphan rows from all custom (_cstm) tables — rows with no matching core table record.');
        $this->addConfirmationOption();
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $this->confirmDestructiveAction(
            $input,
            $output,
            'This permanently deletes orphaned custom-table rows with no backup.',
        );

        global $beanList, $app_list_strings;

        $db = \DBManagerFactory::getInstance();
        $connection = $db->getConnection();
        $fullModuleList = array_merge($beanList, $app_list_strings['moduleList'] ?? []);

        $processedTables = [];
        $totalDeleted = 0;

        foreach (array_keys($fullModuleList) as $module) {
            $bean = \BeanFactory::newBean($module);

            if (!$bean instanceof \SugarBean ||
                isset($processedTables[$bean->table_name]) ||
                !method_exists($bean, 'hasCustomFields') ||
                !$bean->hasCustomFields()
            ) {
                continue;
            }

            $processedTables[$bean->table_name] = true;
            $customTable = $bean->get_custom_table_name();

            while (true) {
                $orphanIds = $connection->createQueryBuilder()
                    ->select('cstm.id_c')
                    ->from($customTable, 'cstm')
                    ->leftJoin('cstm', $bean->table_name, 'core', 'cstm.id_c = core.id')
                    ->where('core.id IS NULL')
                    ->setMaxResults(self::BATCH_SIZE)
                    ->executeQuery()
                    ->fetchFirstColumn();

                if ([] === $orphanIds) {
                    break;
                }

                $deleteBuilder = $connection->createQueryBuilder();
                $deleteBuilder->delete($customTable)
                    ->where($deleteBuilder->expr()->in('id_c', $deleteBuilder->createPositionalParameter(
                        $orphanIds,
                        Connection::PARAM_STR_ARRAY,
                    )))
                    ->executeStatement();

                $totalDeleted += count($orphanIds);
                $output->writeln(sprintf('Deleted %d orphan row(s) from %s', count($orphanIds), $customTable));
            }
        }

        $output->writeln(sprintf('Total orphan rows deleted: %d', $totalDeleted));
    }
}
