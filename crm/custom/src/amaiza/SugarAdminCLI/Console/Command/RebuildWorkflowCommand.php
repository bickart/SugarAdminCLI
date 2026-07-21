<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Rebuild WorkFlow".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/RebuildWorkFlow.php is a self-contained procedural
 * script (it declares `global $beanFiles; global $mod_strings; global $db;`
 * itself, and requires include/workflow/plugin_utils.php internally), so no
 * extra scope work is needed beyond giving it a real $mod_strings value.
 */
class RebuildWorkflowCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:workflow')
            ->setDescription('Rebuild WorkFlow — rebuilds the workflow cache and compiles plugins.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        global $mod_strings;
        $mod_strings = return_module_language('en_us', 'Administration');

        require 'modules/Administration/RebuildWorkFlow.php';
    }
}
