<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports the largest tables in the database by total size (data + index),
 * via information_schema.TABLES — directly useful for deciding what to
 * target with amaiza:admin:repair:prune-database --table or
 * amaiza:admin:repair:prune-bpm-tables. Not a stock Sugar report — no equivalent
 * exists. Read-only, no destructive path at all.
 *
 * TABLE_ROWS from information_schema is InnoDB's own internal estimate, not
 * an exact count (documented MySQL behavior) — reported as an approximation,
 * not a precise figure.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class ReportTableSizesCommand extends AbstractRepairCommand {
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:report:table-sizes')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Number of largest tables to report (default: 20)', '20')
            ->setDescription('Report the largest tables by total size (data + index).');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $top = (int) $input->getOption('top');

        if ($top <= 0) {
            throw new \RuntimeException('--top must be a positive integer.');
        }

        $connection = \DBManagerFactory::getInstance()->getConnection();
        $database = $connection->getDatabase();

        $rows = $connection->createQueryBuilder()
            ->select('TABLE_NAME AS table_name', 'TABLE_ROWS AS table_rows', '(DATA_LENGTH + INDEX_LENGTH) AS total_bytes')
            ->from('information_schema.TABLES')
            ->where('TABLE_SCHEMA = :database')
            ->setParameter('database', $database)
            ->orderBy('total_bytes', 'DESC')
            ->setMaxResults($top)
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $rows) {
            $output->writeln('No tables found.');

            return;
        }

        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '%s — %s (~%s rows)',
                $row['table_name'],
                $this->formatBytes((int) $row['total_bytes']),
                number_format((int) $row['table_rows']),
            ));
        }
    }

    private function formatBytes(int $bytes): string
    {
        $value = $bytes;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count(self::UNITS) - 1) {
            $value /= 1024;
            ++$unitIndex;
        }

        return sprintf('%.2f %s', $value, self::UNITS[$unitIndex]);
    }
}
