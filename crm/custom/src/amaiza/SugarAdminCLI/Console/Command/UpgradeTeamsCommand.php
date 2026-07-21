<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Upgrade Teams".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/upgradeTeams.php is a plain procedural script with
 * no function wrapper. $_REQUEST['upgradeWizard'] only gates its per-user
 * progress echo (same "bug 10339" pattern as install_actions.php), the
 * actual team-creation logic runs unconditionally.
 *
 * The file references bare $mod_strings with no `global` declaration of its
 * own (unlike install_actions.php), so it must be globalized here before the
 * require — otherwise `require` shares our method's local scope, not the
 * top-level scope this file was written to be include()'d into.
 */
class UpgradeTeamsCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:teams:upgrade')
            ->setDescription('Upgrade Teams — creates teams for users.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        global $mod_strings;
        $mod_strings = return_module_language('en_us', 'Administration');
        $_REQUEST['upgradeWizard'] = 'silent';

        require 'modules/Administration/upgradeTeams.php';
    }
}
