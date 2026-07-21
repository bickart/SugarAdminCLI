<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports scheduled jobs stuck in "running" and recent job failures.
 * Confirmed the SchedulersJob bean's real underlying table is job_queue,
 * not schedulers_jobs. Not a stock Sugar report — no equivalent exists.
 * Read-only, no destructive path at all.
 *
 * job_queue.date_modified is NOT a live heartbeat during a long-running
 * job's execution — it's only touched again when the job bean is saved on
 * final resolution (succeedJob()/postponeJob()/failJob()). That's still the
 * right signal for "stuck," though: a status='running' row only gets that
 * status (and its date_modified stamp) set once, when the job actually
 * started, so an old date_modified on a still-'running' row means it
 * genuinely hasn't progressed since then, not that it just hasn't
 * heartbeated recently.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class ReportStuckJobsCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:report:stuck-jobs')
            ->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'Flag "running" jobs whose date_modified is older than this many minutes (default: 60)', '60')
            ->addOption('failure-hours', null, InputOption::VALUE_REQUIRED, 'Also report jobs that resolved as failure within this many hours (default: 24)', '24')
            ->setDescription('Report stuck "running" scheduled jobs and recent job failures (job_queue table).');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $minutes = (int) $input->getOption('minutes');
        $failureHours = (int) $input->getOption('failure-hours');

        if ($minutes <= 0 || $failureHours <= 0) {
            throw new \RuntimeException('--minutes and --failure-hours must be positive integers.');
        }

        $connection = \DBManagerFactory::getInstance()->getConnection();

        $this->reportStuckJobs($connection, $minutes, $output);
        $this->reportRecentFailures($connection, $failureHours, $output);
    }

    private function reportStuckJobs(Connection $connection, int $minutes, OutputInterface $output): void
    {
        $threshold = new \DateTime()->sub(new \DateInterval('PT'.$minutes.'M'))->format('Y-m-d H:i:s');

        $rows = $connection->createQueryBuilder()
            ->select('id', 'name', 'target', 'date_entered', 'date_modified')
            ->from('job_queue')
            ->where('status = :status')
            ->andWhere('deleted = 0')
            ->andWhere('date_modified < :threshold')
            ->setParameter('status', 'running')
            ->setParameter('threshold', $threshold)
            ->orderBy('date_modified', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $rows) {
            $output->writeln(sprintf('No jobs stuck "running" for more than %d minute(s).', $minutes));

            return;
        }

        $now = new \DateTime();

        foreach ($rows as $row) {
            $modified = new \DateTime((string) $row['date_modified']);
            $minutesAgo = (int) (($now->getTimestamp() - $modified->getTimestamp()) / 60);

            $output->writeln(sprintf(
                '[STUCK] "%s" (%s) — target: %s, last touched %s (%d min ago)',
                $row['name'],
                $row['id'],
                $row['target'],
                $row['date_modified'],
                $minutesAgo,
            ));
        }

        $output->writeln(sprintf('Total stuck job(s): %d', count($rows)));
    }

    private function reportRecentFailures(Connection $connection, int $failureHours, OutputInterface $output): void
    {
        $threshold = new \DateTime()->sub(new \DateInterval('PT'.$failureHours.'H'))->format('Y-m-d H:i:s');

        $rows = $connection->createQueryBuilder()
            ->select('id', 'name', 'target', 'date_modified', 'message')
            ->from('job_queue')
            ->where('resolution = :resolution')
            ->andWhere('deleted = 0')
            ->andWhere('date_modified > :threshold')
            ->setParameter('resolution', 'failure')
            ->setParameter('threshold', $threshold)
            ->orderBy('date_modified', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $rows) {
            $output->writeln(sprintf('No job failures in the last %d hour(s).', $failureHours));

            return;
        }

        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '[FAILED] "%s" (%s) — target: %s, failed at %s: %s',
                $row['name'],
                $row['id'],
                $row['target'],
                $row['date_modified'],
                '' !== trim((string) ($row['message'] ?? '')) ? trim((string) $row['message']) : 'n/a',
            ));
        }

        $output->writeln(sprintf('Total recent failure(s): %d', count($rows)));
    }
}
