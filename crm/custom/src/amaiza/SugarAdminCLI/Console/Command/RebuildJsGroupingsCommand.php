<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use SugarMinifyUtils;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Rebuild JS Grouping Files".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/RepairJSFile.php only renders a warning page whose
 * JS then AJAX-posts to action=callJSRepair, which sets
 * $_REQUEST['js_rebuild_concat']='rebuild' and requires jssource/minify.php
 * — itself a script built for either browser-AJAX mode or a $argv-driven
 * CLI mode with several unrelated backup/restore flags (-r/-m/-c). Rather
 * than faking either of those entry paths, we call the same underlying
 * method both paths ultimately call: SugarMinifyUtils::ConcatenateFiles().
 */
class RebuildJsGroupingsCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:repair:js-groupings')
            ->setDescription('Rebuild JS Grouping Files — re-concatenates and overwrites existing group files with the latest versions.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        require_once 'jssource/minify_utils.php';

        new \SugarMinifyUtils()->ConcatenateFiles(getcwd());
    }
}
