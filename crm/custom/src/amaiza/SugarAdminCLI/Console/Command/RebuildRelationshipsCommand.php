<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Rebuild Relationships".
 *
 * The stock modules/Administration/RebuildRelationship.php is a two-line
 * wrapper around this exact call (plus an optional "done" echo gated on
 * $_REQUEST['silent']); calling the class method directly avoids needing
 * to require a file just for its side effect.
 */
class RebuildRelationshipsCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:relationships')
            ->setDescription('Rebuild Relationships — rebuilds relationship metadata and drops the cache file.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        \SugarRelationshipFactory::rebuildCache();
    }
}
