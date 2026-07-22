<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Rebuild Sugar Logic Functions".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/RebuildExpressionPlugins.php is only an
 * is_admin($current_user) guard around `$GLOBALS['updateSilent'] = false;
 * require_once 'include/Expressions/updatecache.php';` — we bypass that
 * outer guard (our CLI context always runs as the system admin already,
 * same reasoning the QRR commands use) and require the real cache-rebuild
 * file directly. It reads $GLOBALS['updateSilent'] via superglobal array
 * access rather than a bare variable, so no scope juggling is needed here.
 */
class RebuildSugarLogicCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:repair:sugarlogic')
            ->setDescription('Rebuild Sugar Logic Functions — rebuilds the Sugar Logic functions cache.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $GLOBALS['updateSilent'] = false;

        require_once 'include/Expressions/updatecache.php';
    }
}
