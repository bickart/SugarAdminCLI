<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

class MaintenanceOnCommand extends AbstractMaintenanceModeCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:maintenance:on')
            ->setDescription('Set Maintenance mode on — the system becomes UI-accessible to administrators only.');
    }

    protected function targetStatus(): bool
    {
        return true;
    }
}
