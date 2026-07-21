<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

require_once 'modules/Administration/QuickRepairAndRebuild.php';

/**
 * Equivalent of Administration > Repair > "Clear Additional Cache".
 *
 * modules/Administration/ClearAdditionalCache.php is a 4-line wrapper
 * around this exact sequence; calling it directly avoids requiring a file
 * just for its side effect. show_output=false means its internal
 * `global $mod_strings` echo branch is skipped, so no extra scope setup
 * is needed here.
 */
class ClearAdditionalCacheCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:cache:clear')
            ->setDescription('Clear Additional Cache — removes cached files used by additional resources (API, etc).');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $repair = new \RepairAndClear();
        $repair->show_output = false;
        $repair->module_list = [];
        $repair->clearAdditionalCaches();
    }
}
