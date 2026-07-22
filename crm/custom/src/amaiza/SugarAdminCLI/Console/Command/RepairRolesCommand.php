<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Repair Roles".
 *
 * modules/ACL/install_actions.php has no wrapper function — it's a
 * procedural script that runs unconditionally for an admin user; the only
 * thing $_REQUEST['upgradeWizard'] gates is its per-module progress echo.
 * Setting it to 'silent' before requiring the file is the same approach
 * already proven in production by claimspay4's own RepairRolesCommand.
 */
class RepairRolesCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:repair:roles')
            ->setDescription('Repair Roles — adds all new modules that support Access Controls, and any new Access Controls to existing modules.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $_REQUEST['upgradeWizard'] = 'silent';
        require_once 'modules/ACL/install_actions.php';
    }
}
