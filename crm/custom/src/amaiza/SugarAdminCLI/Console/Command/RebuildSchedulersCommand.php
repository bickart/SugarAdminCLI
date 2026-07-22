<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use BeanFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Rebuild Schedulers".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/RebuildSchedulers.php is a form+handler combo; the
 * actual work on POST perform_rebuild=true is one call:
 * BeanFactory::newBean('Schedulers')->rebuildDefaultSchedulers(). Calling
 * that bean method directly avoids the surrounding form/template chrome.
 */
class RebuildSchedulersCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:repair:schedulers')
            ->setDescription('Rebuild Schedulers — rebuilds out-of-the-box Scheduler Jobs.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        \BeanFactory::newBean('Schedulers')->rebuildDefaultSchedulers();
    }
}
