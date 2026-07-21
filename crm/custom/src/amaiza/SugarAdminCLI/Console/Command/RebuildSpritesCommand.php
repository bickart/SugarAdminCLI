<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Rebuild Sprites".
 *
 * modules/Administration/RebuildSprites.php (the admin UI action) is pure
 * chrome — it renders a Smarty template whose JS then AJAX-posts to
 * action=callRebuildSprites, which is what actually calls rebuildSprites().
 * We bypass the UI shell entirely and call the real function directly.
 */
class RebuildSpritesCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:sprites')
            ->setDescription('Rebuild Sprites — rebuilds the sprite images and configuration files.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        require_once 'modules/UpgradeWizard/uw_utils.php';

        rebuildSprites(false);
    }
}
