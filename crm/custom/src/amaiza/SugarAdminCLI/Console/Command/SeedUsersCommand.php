<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Enable/Disable Seed Users".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/RepairSeedUsers.php does its own
 * `global $current_user, $mod_strings;` and reads $_POST['activate']
 * ('true' => Active, 'false' => Inactive) via a raw UPDATE ... WHERE id LIKE
 * 'seed%'. It also renders a follow-up form using $smarty, referenced bare
 * with no global of its own — harmless here since that HTML is discarded,
 * but worth knowing if a future change starts relying on that render path.
 */
class SeedUsersCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:seed-users')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Activate the demo seed users')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Deactivate the demo seed users')
            ->setDescription('Enable/Disable Seed Users — quickly enable or disable seed users populated during demo installation.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $enable = (bool) $input->getOption('enable');
        $disable = (bool) $input->getOption('disable');

        if ($enable === $disable) {
            throw new \RuntimeException('Pass exactly one of --enable or --disable.');
        }

        global $current_user, $mod_strings;
        $mod_strings = return_module_language('en_us', 'Administration');
        $_POST['activate'] = $enable ? 'true' : 'false';

        require 'modules/Administration/RepairSeedUsers.php';
    }
}
