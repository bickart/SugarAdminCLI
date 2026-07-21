<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Repair Teams".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/RepairTeams.php only self-invokes its own
 * process_team_access() call when $_REQUEST['silent'] is present AND
 * loosely equals 0 (a confusing, easy-to-misread gate) — we deliberately
 * leave that unset so requiring the file just defines its functions/class
 * without running anything, then call process_team_access() ourselves
 * directly with every option enabled (matches what checking every checkbox
 * in the admin UI form would submit). The function globalizes $mod_strings
 * itself; it just needs a real value assigned first.
 */
class RepairTeamsCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:teams')
            ->setDescription('Repair Teams — rebuilds private team memberships based on the user reporting hierarchy.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        global $mod_strings;
        $mod_strings = return_module_language('en_us', 'Administration');

        require_once 'modules/Administration/RepairTeams.php';

        process_team_access(true, true, true, '1', true);
    }
}
