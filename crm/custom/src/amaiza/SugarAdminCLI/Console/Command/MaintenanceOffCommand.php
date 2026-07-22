<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

class MaintenanceOffCommand extends AbstractMaintenanceModeCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:maintenance:off')
            ->setDescription('Set Maintenance mode off — the system becomes accessible to all users again.');
    }

    protected function targetStatus(): bool
    {
        return false;
    }
}
