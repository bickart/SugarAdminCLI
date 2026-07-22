<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Repair Activities".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/RepairActivities.php references bare $current_user
 * (its is_admin() guard, before any global declaration), $timedate (which it
 * does its own `global $timedate;` for), and bare $mod_strings at the very
 * end with no global declaration at all — all three must be real globals in
 * our scope before requiring, or the guard/echo silently fail.
 */
class RepairActivitiesCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:repair:activities')
            ->setDescription('Repair Activities — repairs Activities (Calls, Meetings) end dates.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        global $current_user, $timedate, $mod_strings;
        $mod_strings = return_module_language('en_us', 'Administration');

        require 'modules/Administration/RepairActivities.php';
    }
}
