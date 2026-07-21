<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports Customer Journeys ("Smart Guide" in the UI, dri_workflows table)
 * still marked in_progress well past a reasonable running time — likely
 * candidates for admin:report:blocked-record --cancel-journey. Verified
 * directly against modules/DRI_Workflows/vardefs.php and DRI_Workflow.php's
 * STATE_* constants: state='in_progress', archived is a real bool column,
 * date_started/parent_type/parent_id are all real stored columns. Confirmed
 * live against keecor: parent_name is declared in vardefs as a 'parent' type
 * field but is NOT an actual stored column ($db->get_columns() omits it) —
 * it's a display-only computed value, so the parent record's name is
 * resolved here via a bean lookup instead of selecting a column that
 * doesn't exist.
 *
 * Read-only — no equivalent Sugar core report exists for this.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class ReportStaleJourneysCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:report:stale-journeys')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Report journeys started more than this many days ago (default: 30)', '30')
            ->setDescription('Report Customer Journeys ("Smart Guide") still in_progress well past a reasonable running time.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $days = (int) $input->getOption('days');

        if ($days <= 0) {
            throw new \RuntimeException('--days must be a positive integer.');
        }

        $connection = \DBManagerFactory::getInstance()->getConnection();
        $threshold = new \DateTime()->sub(new \DateInterval('P'.$days.'D'))->format('Y-m-d H:i:s');

        $rows = $connection->createQueryBuilder()
            ->select('id', 'name', 'date_started', 'parent_type', 'parent_id')
            ->from('dri_workflows')
            ->where('state = :state')
            ->andWhere('archived = 0')
            ->andWhere('deleted = 0')
            ->andWhere('date_started < :threshold')
            ->setParameter('state', 'in_progress')
            ->setParameter('threshold', $threshold)
            ->orderBy('date_started', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $rows) {
            $output->writeln(sprintf('No journeys in_progress for more than %d day(s).', $days));

            return;
        }

        $now = new \DateTime();

        foreach ($rows as $row) {
            $started = new \DateTime((string) $row['date_started']);
            $daysRunning = $started->diff($now)->days;
            $parentName = $this->resolveParentName((string) $row['parent_type'], (string) $row['parent_id']);

            $output->writeln(sprintf(
                '"%s" (id: %s) — running %d day(s), started %s, parent: %s "%s"',
                $row['name'],
                $row['id'],
                $daysRunning,
                $row['date_started'],
                $row['parent_type'] ?: 'n/a',
                $parentName,
            ));
        }

        $output->writeln(sprintf('Total stale journeys: %d', count($rows)));
    }

    private function resolveParentName(string $parentType, string $parentId): string
    {
        if ('' === $parentType || '' === $parentId) {
            return 'n/a';
        }

        $parent = \BeanFactory::retrieveBean($parentType, $parentId);

        return $parent instanceof \SugarBean ? (string) $parent->name : 'n/a (deleted?)';
    }
}
