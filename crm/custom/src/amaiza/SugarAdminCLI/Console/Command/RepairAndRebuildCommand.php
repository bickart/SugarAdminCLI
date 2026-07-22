<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use RepairAndClear;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

require_once 'modules/Administration/QuickRepairAndRebuild.php';

/**
 * Equivalent of Administration > Repair > "Quick Repair and Rebuild".
 *
 * Calls RepairAndClear::repairAndClearAll() directly — the same call the
 * stock ViewRepair::display() makes — rather than requiring
 * modules/Administration/views/view.repair.php, since that file also
 * renders the admin UI page chrome around the repair call.
 */
class RepairAndRebuildCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:repair:qrr')
            ->setAliases(['amaiza:admin:qrr'])
            ->setDescription('Quick Repair and Rebuild — repairs/rebuilds DB, Extensions, Vardefs, Dashlets, etc.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $GLOBALS['mod_strings'] = return_module_language('en_us', 'Administration');

        new \RepairAndClear()->repairAndClearAll(
            ['clearAll'],
            [translate('LBL_ALL_MODULES')],
            true,
            false,
            '',
        );

        \LanguageManager::removeJSLanguageFiles();
        \LanguageManager::clearLanguageCache();
    }
}
