<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Repair Non-Lowercase Fields".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/RepairFieldCasing.php gates its entire body on a
 * bare `is_admin($current_user)` check with no `global $current_user;`
 * declared before it — that must be globalized here or the check silently
 * evaluates against an undefined variable and the whole repair no-ops.
 * $mod_strings is globalized by the file itself, but still needs a real
 * value assigned before the require.
 */
class RepairFieldCasingCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:field-casing')
            ->setDescription('Repair Non-Lowercase Fields — repairs mixed-case custom table(s) and metadata file(s).');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        global $current_user, $mod_strings;
        $mod_strings = return_module_language('en_us', 'Administration');

        require 'modules/Administration/RepairFieldCasing.php';
    }
}
