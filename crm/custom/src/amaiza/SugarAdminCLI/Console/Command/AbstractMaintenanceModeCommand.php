<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Configurator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Toggles Sugar's maintenanceMode config setting.
 *
 * Not a stock Administration > Repair action — no equivalent exists in Sugar
 * core (maintenanceMode is normally only ever set by hand-editing
 * config_override.php). Behavior modeled on esimonetti/toothpaste's
 * local:maintenance:on/off commands (Apache-2.0), reimplemented here
 * directly against Sugar's own Configurator class rather than reusing that
 * project's code. While enabled, the system is only accessible via the UI
 * to administrator users.
 */
abstract class AbstractMaintenanceModeCommand extends AbstractRepairCommand {
    abstract protected function targetStatus(): bool;

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $newStatus = $this->targetStatus();
        $label = $newStatus ? 'on' : 'off';

        if (($GLOBALS['sugar_config']['maintenanceMode'] ?? false) === $newStatus) {
            $output->writeln(sprintf('maintenanceMode is already set to: %s', $label));

            return;
        }

        $configurator = new \Configurator();
        $configurator->config['maintenanceMode'] = $newStatus;
        $configurator->handleOverride();

        $output->writeln(sprintf('maintenanceMode is now set to: %s', $label));
        $output->writeln($newStatus
            ? 'The system is ONLY accessible via the UI by ADMINISTRATOR users.'
            : 'The system is accessible via the UI by all users.');
    }
}
