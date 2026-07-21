<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Rebuild Javascript Languages".
 *
 * modules/Administration/RebuildJSLang.php (behind an is_admin() guard) is
 * just these two static calls — calling them directly avoids the guard,
 * which we don't need in a CLI context that already runs as the system
 * admin.
 */
class RebuildJsLanguagesCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:js-languages')
            ->setDescription('Rebuild Javascript Languages — rebuilds javascript versions of language files.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        \LanguageManager::removeJSLanguageFiles();
        \LanguageManager::clearLanguageCache();
    }
}
