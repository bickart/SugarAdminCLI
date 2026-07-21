<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Rebuild Config File".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/RebuildConfig.php only performs the rebuild when
 * $_POST['perform_rebuild'] is truthy and config.php is writable; otherwise
 * it just renders a status template. It calls the clean, directly-callable
 * loadCleanConfig()/rebuildConfigFile() pair from include/utils.php.
 *
 * The file declares `global $mod_strings;` itself but references bare
 * $sugar_version with no global declaration — that one must be globalized
 * here or rebuildConfigFile() gets called with a null version.
 */
class RebuildConfigCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:config')
            ->setDescription('Rebuild Config File — rebuilds config.php, updating version and adding defaults when not explicitly declared.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        global $mod_strings, $sugar_version;
        $mod_strings = return_module_language('en_us', 'Administration');
        $_POST['perform_rebuild'] = true;

        require 'modules/Administration/RebuildConfig.php';
    }
}
