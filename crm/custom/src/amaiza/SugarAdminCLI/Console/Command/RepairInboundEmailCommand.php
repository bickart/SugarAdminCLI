<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Repair Inbound Email Accounts".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/RepairIE.php's own logic is just this query loop
 * plus one method call per account; reproduced directly here rather than
 * requiring the file, since the file's only other job is echoing a
 * pass/fail summary with links back into the UI, which is meaningless in a
 * CLI context.
 */
class RepairInboundEmailCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:repair:inbound-email')
            ->setDescription('Repair Inbound Email Accounts — repairs accounts and encrypts account passwords.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $db = \DBManagerFactory::getInstance();
        $result = $db->query("SELECT id, name FROM inbound_email WHERE deleted=0 AND status='Active'");

        $failed = [];
        while ($row = $db->fetchByAssoc($result)) {
            $account = \BeanFactory::getBean('InboundEmail', $row['id'], ['disable_row_level_security' => true]);
            if (!$account->repairAccount()) {
                $failed[] = $row['name'];
            }
        }

        if ([] !== $failed) {
            throw new \RuntimeException(sprintf('Failed to repair: %s', implode(', ', $failed)));
        }
    }
}
