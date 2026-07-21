<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exports full record data (core table row + _cstm row if the module has
 * custom fields) for a given list of ids to a JSON Lines file — a manual
 * safety net for use before running a destructive command, or standalone.
 *
 * Not a stock Administration > Repair action — no equivalent exists in Sugar
 * core, and this package's own destructive commands (orphans-cleanup,
 * prune-database, orphaned-parent-cleanup) each have their own --backup-dir
 * option using the same AbstractRepairCommand::appendBackupRows() mechanism
 * this command uses directly.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class ExportRecordsCommand extends AbstractRepairCommand {
    private const DEFAULT_BACKUP_DIR = './sugaradmincli-backups';

    protected function configure(): void
    {
        $this
            ->setName('admin:repair:export-records')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module of the records to export')
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'Comma-separated record IDs to export')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, sprintf(
                'Output file path (default: a timestamped file under %s)',
                self::DEFAULT_BACKUP_DIR,
            ))
            ->setDescription('Export full record data (core + custom fields) to a JSON Lines file.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $module = (string) $input->getOption('module');
        $idsOption = (string) $input->getOption('ids');

        if ('' === $module || '' === trim($idsOption)) {
            throw new \RuntimeException('Both --module and --ids are required.');
        }

        $ids = array_values(array_filter(array_map('trim', explode(',', $idsOption))));

        if ([] === $ids) {
            throw new \RuntimeException('--ids did not contain any record IDs.');
        }

        $probe = \BeanFactory::newBean($module);

        if (!$probe instanceof \SugarBean) {
            throw new \RuntimeException(sprintf('Unknown module "%s".', $module));
        }

        $connection = \DBManagerFactory::getInstance()->getConnection();
        $hasCustomFields = method_exists($probe, 'hasCustomFields') && $probe->hasCustomFields();

        $records = [];

        foreach ($ids as $id) {
            $core = $connection->createQueryBuilder()
                ->select('*')
                ->from($probe->table_name)
                ->where('id = :id')
                ->setParameter('id', $id)
                ->executeQuery()
                ->fetchAssociative();

            if (false === $core) {
                $output->writeln(sprintf('Skipping "%s": no core row found in %s.', $id, $probe->table_name));

                continue;
            }

            $record = ['module' => $module, 'table' => $probe->table_name, 'core' => $core];

            if ($hasCustomFields) {
                $custom = $connection->createQueryBuilder()
                    ->select('*')
                    ->from($probe->get_custom_table_name())
                    ->where('id_c = :id')
                    ->setParameter('id', $id)
                    ->executeQuery()
                    ->fetchAssociative();

                if (false !== $custom) {
                    $record['custom'] = $custom;
                }
            }

            $records[] = $record;
        }

        $outputOption = (string) $input->getOption('output');
        $path = '' !== trim($outputOption)
            ? $outputOption
            : $this->defaultBackupPath(self::DEFAULT_BACKUP_DIR, $module);

        $this->appendBackupRows($path, $records);

        $output->writeln(sprintf('Exported %d of %d requested record(s) to %s', count($records), count($ids), $path));
    }
}
