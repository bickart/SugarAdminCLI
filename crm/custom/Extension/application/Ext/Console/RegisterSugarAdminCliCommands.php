<?php

use Sugarcrm\Sugarcrm\Console\CommandRegistry\CommandRegistry;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\ClearAdditionalCacheCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RebuildConfigCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RebuildHtaccessCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RebuildJsGroupingsCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RebuildJsLanguagesCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RebuildRelationshipsCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RebuildSchedulersCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RebuildSpritesCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RebuildSugarLogicCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RebuildWorkflowCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RemoveXssCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RepairActivitiesCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RepairAndRebuildCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RepairFieldCasingCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RepairInboundEmailCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RepairRolesCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\RepairTeamsCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\SeedUsersCommand;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\UpgradeTeamsCommand;

// Register all SugarAdminCLI admin:repair:* commands.
CommandRegistry::getInstance()->addCommands([
    new RepairAndRebuildCommand(),
    new UpgradeTeamsCommand(),
    new RebuildHtaccessCommand(),
    new RebuildConfigCommand(),
    new RebuildSugarLogicCommand(),
    new RebuildRelationshipsCommand(),
    new RebuildSchedulersCommand(),
    new RebuildWorkflowCommand(),
    new RebuildJsLanguagesCommand(),
    new RebuildJsGroupingsCommand(),
    new RebuildSpritesCommand(),
    new RepairFieldCasingCommand(),
    new RepairTeamsCommand(),
    new RepairRolesCommand(),
    new RepairInboundEmailCommand(),
    new RemoveXssCommand(),
    new RepairActivitiesCommand(),
    new SeedUsersCommand(),
    new ClearAdditionalCacheCommand(),
]);
