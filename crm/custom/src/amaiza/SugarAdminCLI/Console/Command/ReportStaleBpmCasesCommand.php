<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Sugarcrm\Sugarcrm\ProcessManager\Factory as PMSEFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnoses (and optionally terminates) stuck real SugarBPM cases — a
 * separate system from the Customer Journey engine ("Smart Guide"), which
 * has its own admin:report:blocked-record/stale-journeys commands. Verified
 * directly against modules/pmse_Inbox/clients/base/api/PMSEEngineApi.php
 * (cancelCase()) and modules/pmse_Inbox/engine/PMSEHandlers/PMSECaseFlowHandler.php
 * (terminateCase()) — the exact classes/methods the stock "Terminate" admin
 * action itself uses.
 *
 * pmse_Inbox.cas_status = 'IN PROGRESS' is the authoritative "is this case
 * still running" signal (confirmed via PMSEEngineUtils::getBPMInboxStatus()
 * and PMSEEngineApi::getUnattendedCases(), both of which check exactly
 * this) — pmse_bpm_flow.cas_flow_status is a per-step/per-node value with a
 * completely different vocabulary ('NEW'/'FORM'/'WAITING'/etc, never
 * 'IN PROGRESS') and isn't authoritative for the case as a whole.
 *
 * --terminate replicates cancelCase()'s own pre-check (skip if cas_status is
 * already CANCELLED/TERMINATED/COMPLETED) before calling the real
 * PMSECaseFlowHandler::terminateCase() — not a from-scratch reimplementation
 * of the cancel logic itself.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class ReportStaleBpmCasesCommand extends AbstractRepairCommand {
    private const TERMINAL_STATUSES = ['CANCELLED', 'TERMINATED', 'COMPLETED'];

    protected function configure(): void
    {
        $this
            ->setName('admin:report:stale-bpm-cases')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Report cases started more than this many days ago (default: 30)', '30')
            ->addOption('case-id', null, InputOption::VALUE_REQUIRED, 'Report (or terminate) only this one pmse_Inbox cas_id, regardless of age')
            ->addOption('terminate', null, InputOption::VALUE_NONE, 'Terminate matched cases (mirrors the stock "Terminate" admin action, PMSECaseFlowHandler::terminateCase())')
            ->setDescription('Report (and optionally terminate) SugarBPM cases still IN PROGRESS well past a reasonable running time.');
        $this->addConfirmationOption();
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $caseIdOption = (string) $input->getOption('case-id');
        $terminate = (bool) $input->getOption('terminate');

        $cases = '' !== $caseIdOption
            ? $this->findByCaseId($caseIdOption, $output)
            : $this->findStale((int) $input->getOption('days'), $output);

        if ([] === $cases) {
            return;
        }

        if ($terminate) {
            $this->confirmDestructiveAction(
                $input,
                $output,
                'This terminates each matched case (mirrors the stock "Terminate" admin action) — cannot be undone.',
            );

            foreach ($cases as $case) {
                $this->terminateCase($case, $output);
            }
        }
    }

    /**
     * @return list<int|string>
     */
    private function findByCaseId(string $caseId, OutputInterface $output): array
    {
        $inbox = \BeanFactory::newBean('pmse_Inbox');
        $inbox->retrieve_by_string_fields(['cas_id' => $caseId]);

        if (empty($inbox->id)) {
            $output->writeln(sprintf('No case found with cas_id "%s".', $caseId));

            return [];
        }

        $this->reportCase($inbox, $output);

        return [$inbox->cas_id];
    }

    /**
     * @return list<int|string>
     */
    private function findStale(int $days, OutputInterface $output): array
    {
        if ($days <= 0) {
            throw new \RuntimeException('--days must be a positive integer.');
        }

        $connection = \DBManagerFactory::getInstance()->getConnection();
        $threshold = new \DateTime()->sub(new \DateInterval('P'.$days.'D'))->format('Y-m-d H:i:s');

        $rows = $connection->createQueryBuilder()
            ->select('id', 'cas_id', 'cas_title', 'pro_title', 'cas_create_date')
            ->from('pmse_inbox')
            ->where('cas_status = :status')
            ->andWhere('deleted = 0')
            ->andWhere('cas_create_date < :threshold')
            ->setParameter('status', 'IN PROGRESS')
            ->setParameter('threshold', $threshold)
            ->orderBy('cas_create_date', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $rows) {
            $output->writeln(sprintf('No cases IN PROGRESS for more than %d day(s).', $days));

            return [];
        }

        $now = new \DateTime();

        foreach ($rows as $row) {
            $started = new \DateTime((string) $row['cas_create_date']);
            $output->writeln(sprintf(
                '"%s" (cas_id: %s, process: %s) — running %d day(s), started %s',
                $row['cas_title'],
                $row['cas_id'],
                $row['pro_title'] ?: 'n/a',
                $started->diff($now)->days,
                $row['cas_create_date'],
            ));
        }

        $output->writeln(sprintf('Total stale cases: %d', count($rows)));

        return array_column($rows, 'cas_id');
    }

    private function reportCase(\SugarBean $inbox, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            '"%s" (cas_id: %s, process: %s) — status: %s, created: %s',
            $inbox->cas_title,
            $inbox->cas_id,
            $inbox->pro_title ?: 'n/a',
            $inbox->cas_status,
            $inbox->cas_create_date,
        ));
    }

    private function terminateCase(int|string $caseId, OutputInterface $output): void
    {
        $inbox = \BeanFactory::newBean('pmse_Inbox');
        $inbox->retrieve_by_string_fields(['cas_id' => $caseId]);

        if (empty($inbox->id)) {
            $output->writeln(sprintf('cas_id %s: no longer exists, skipping.', $caseId));

            return;
        }

        if (in_array($inbox->cas_status, self::TERMINAL_STATUSES, true)) {
            $output->writeln(sprintf('cas_id %s: already %s, skipping.', $caseId, $inbox->cas_status));

            return;
        }

        $flow = \BeanFactory::newBean('pmse_BpmFlow');
        $flow->retrieve_by_string_fields(['cas_id' => $caseId]);

        if (empty($flow->id)) {
            $output->writeln(sprintf('cas_id %s: no pmse_bpm_flow row found, skipping.', $caseId));

            return;
        }

        $bean = \BeanFactory::retrieveBean($flow->cas_sugar_module, $flow->cas_sugar_object_id);

        if (!$bean instanceof \SugarBean) {
            $output->writeln(sprintf(
                'cas_id %s: linked record "%s" in "%s" no longer exists, skipping.',
                $caseId,
                $flow->cas_sugar_object_id,
                $flow->cas_sugar_module,
            ));

            return;
        }

        try {
            $caseFlowHandler = PMSEFactory::getPMSEObject('PMSECaseFlowHandler');
            $caseFlowHandler->terminateCase(['cas_id' => $caseId], $bean, 'CANCELLED');
            $output->writeln(sprintf('cas_id %s: terminated.', $caseId));
        } catch (\Throwable $exception) {
            $output->writeln(sprintf('cas_id %s: failed to terminate: %s', $caseId, $exception->getMessage()));
        }
    }
}
