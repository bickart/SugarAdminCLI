<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Rebuild .htaccess File".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/UpgradeAccess.php is a plain procedural script; it
 * reads global $mod_strings/$sugar_config and writes .htaccess plus
 * upload/.htaccess directly via file_put_contents(), no request gating.
 */
class RebuildHtaccessCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:repair:htaccess')
            ->setDescription('Rebuild .htaccess File — rebuilds .htaccess to limit access to certain files directly.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        global $mod_strings;
        $mod_strings = return_module_language('en_us', 'Administration');

        require 'modules/Administration/UpgradeAccess.php';
    }
}
