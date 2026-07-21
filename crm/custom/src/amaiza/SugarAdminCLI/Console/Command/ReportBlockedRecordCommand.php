<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Sugarcrm\Sugarcrm\CustomerJourney\Bean\Activity\ActivityHandlerFactory;
use Sugarcrm\Sugarcrm\CustomerJourney\Bean\Journey\Canceller;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnoses (and optionally resolves) a record blocked by the Customer
 * Journey engine ("Smart Guide" in the UI) — a separate system from real
 * SugarBPM (pmse_*), confirmed by reading
 * src/CustomerJourney/LogicHooks/ActivityHooksHelper.php::validateDependency(),
 * which is exactly where the "blocked by an activity/stage in a Smart Guide"
 * save-time errors originate.
 *
 * Only Tasks/Calls/Meetings carry the dri_workflow_id/dri_subworkflow_id
 * field pair (via the shared customer_journey_activity SugarObject vardefs)
 * — any other module is reported as not a Customer Journey activity rather
 * than silently doing nothing.
 *
 * --unblock sets the runtime-only ignore_blocked_by property (confirmed:
 * it has no vardef at all, is never persisted, and is the exact property
 * ActivityHooksHelper::validateDependency() and ActivityHelper::isBlocked()/
 * isBlockedByStage() check) and saves — this bypasses the block for that one
 * save only, it does not change the record's status or the journey itself.
 *
 * --cancel-journey retrieves the DRI_Workflows bean and calls the real
 * Canceller::cancel() (the same class SugarCRM's own "Terminate" UI action
 * uses) — this can hard-delete or mark-not-applicable the journey's other
 * open activities depending on the journey template's cancel_action, so it's
 * gated behind confirmation like --unblock.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class ReportBlockedRecordCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:report:blocked-record')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module of the record to check')
            ->addOption('record', null, InputOption::VALUE_REQUIRED, 'ID of the record to check')
            ->addOption('unblock', null, InputOption::VALUE_NONE, 'Set ignore_blocked_by and save, bypassing the block for this one save')
            ->addOption('cancel-journey', null, InputOption::VALUE_NONE, "Cancel the record's Customer Journey (mirrors the UI's Terminate action)")
            ->setDescription('Report (and optionally resolve) why a record is blocked by a Customer Journey / "Smart Guide".');
        $this->addConfirmationOption();
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $module = (string) $input->getOption('module');
        $recordId = (string) $input->getOption('record');

        if ('' === $module || '' === $recordId) {
            throw new \RuntimeException('Both --module and --record are required.');
        }

        $activity = \BeanFactory::retrieveBean($module, $recordId);

        if (!$activity instanceof \SugarBean) {
            throw new \RuntimeException(sprintf('No "%s" record found with id "%s".', $module, $recordId));
        }

        if (!isset($activity->field_defs['dri_workflow_id'], $activity->field_defs['dri_subworkflow_id'])) {
            $output->writeln(sprintf('%s is not a Customer Journey activity module (no dri_workflow_id/dri_subworkflow_id field).', $module));

            return;
        }

        $journeyId = (string) ($activity->dri_workflow_id ?? '');
        $stageId = (string) ($activity->dri_subworkflow_id ?? '');

        if ('' === $journeyId) {
            $output->writeln(sprintf('%s "%s" is not currently linked to any journey.', $module, $recordId));
        } else {
            $this->reportJourney($journeyId, $output);
        }

        if ('' !== $stageId) {
            $this->reportStage($stageId, $output);
        }

        $helper = ActivityHandlerFactory::factory($module);
        $blockedByActivity = $helper->isBlocked($activity);
        $blockedByStage = $helper->isBlockedByStage($activity);

        $output->writeln(sprintf('Currently blocked by an activity: %s', $blockedByActivity ? 'yes' : 'no'));
        $output->writeln(sprintf('Currently blocked by a stage: %s', $blockedByStage ? 'yes' : 'no'));

        if ($blockedByActivity) {
            $names = array_map(static fn (\SugarBean $bean): string => (string) $bean->name, $helper->getBlockedBy($activity));
            $output->writeln(sprintf('Blocked by: %s', implode(', ', $names)));
        }

        if ((bool) $input->getOption('unblock')) {
            $this->confirmDestructiveAction(
                $input,
                $output,
                'This sets ignore_blocked_by and saves the record, bypassing the Customer Journey block for this one save.',
            );

            $activity->ignore_blocked_by = true;
            $activity->save();
            $output->writeln(sprintf('%s "%s" saved with ignore_blocked_by set.', $module, $recordId));
        }

        if ((bool) $input->getOption('cancel-journey')) {
            if ('' === $journeyId) {
                throw new \RuntimeException('Cannot cancel: this record is not linked to any journey.');
            }

            $this->confirmDestructiveAction(
                $input,
                $output,
                'This cancels the Customer Journey (mirrors the UI\'s Terminate action) and may remove or mark-not-applicable its other open activities.',
            );

            $journey = \BeanFactory::retrieveBean('DRI_Workflows', $journeyId);

            if (!$journey instanceof \DRI_Workflow) {
                throw new \RuntimeException(sprintf('Journey "%s" no longer exists.', $journeyId));
            }

            $result = new Canceller()->cancel($journey);
            $output->writeln(sprintf(
                'Journey cancel result: activity_change_not_allowed=%s, is_child_read_only=%s',
                var_export($result['activity_change_not_allowed'] ?? false, true),
                var_export($result['is_child_read_only'] ?? false, true),
            ));

            $this->reportJourney($journeyId, $output);
        }
    }

    private function reportJourney(string $journeyId, OutputInterface $output): void
    {
        $journey = \BeanFactory::retrieveBean('DRI_Workflows', $journeyId);

        if (!$journey instanceof \SugarBean) {
            $output->writeln(sprintf('Journey "%s" no longer exists.', $journeyId));

            return;
        }

        $output->writeln(sprintf(
            'Journey: "%s" (id: %s) — state: %s, archived: %s, started: %s',
            $journey->name,
            $journey->id,
            $journey->state,
            $journey->archived ? 'yes' : 'no',
            $journey->date_started ?? 'n/a',
        ));
    }

    private function reportStage(string $stageId, OutputInterface $output): void
    {
        $stage = \BeanFactory::retrieveBean('DRI_SubWorkflows', $stageId);

        if (!$stage instanceof \SugarBean) {
            $output->writeln(sprintf('Stage "%s" no longer exists.', $stageId));

            return;
        }

        $output->writeln(sprintf('Stage: "%s" (id: %s) — state: %s', $stage->label ?: $stage->name, $stage->id, $stage->state));
    }
}
