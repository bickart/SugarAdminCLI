<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use RepairAndClear;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Aligns the database schema to Sugar's file-system definitions by creating
 * any SQL table that's missing entirely — the scenario stock QRR
 * (admin:repair:qrr) doesn't reliably cover, since its schema-diffing logic
 * assumes a table already exists to diff against. Intended for recovering an
 * incomplete instance (e.g. restored from a partial backup missing whole
 * tables), not routine maintenance — for that, use admin:repair:qrr.
 *
 * Not a stock Administration > Repair action — no equivalent exists in Sugar
 * core. Behavior modeled on esimonetti/toothpaste's
 * local:system:repair-missing-tables command (Apache-2.0), reimplemented
 * here directly against Sugar's own DBManager/RepairAndClear APIs rather
 * than reusing that project's code.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md. Highest-risk command in this
 * package: it require()s every metadata/*.php, custom/metadata/*.php, and
 * modules/*\/vardefs.php file directly to build a table => {fields,indices}
 * map (the same glob-then-require technique Sugar's own upgrade scripts use
 * internally — there's no cleaner public API for "every declared SQL table
 * across all dictionaries"), which can warn on class/constant redeclaration
 * in some custom-module layouts. custom/metadata/*.php specifically is where
 * every Studio-created custom relationship's join-table definition lives
 * (confirmed against modules/ModuleBuilder/parsers/relationships/DeployedRelationships.php
 * and this repo's own custom/metadata/*.php files) — a missing join table is
 * exactly the kind of thing this command exists to recreate, so omitting
 * that glob would have made the command silently useless for its main use
 * case. Test against a disposable copy of the target instance first.
 */
class RepairMissingTablesCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:missing-tables')
            ->setDescription('Repair missing SQL tables and align the database schema to Sugar\'s definitions.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $db = \DBManagerFactory::getInstance();

        $this->createMissingDictionaryTables($db, $output);
        $this->createMissingModuleTables($db, $output);

        $output->writeln('Running a full system repair...');
        $rac = new \RepairAndClear();
        $rac->execute = true;
        $rac->clearVardefs();
        $rac->rebuildExtensions();
        $rac->clearExternalAPICache();
        $rac->repairDatabase();

        \SugarRelationshipFactory::rebuildCache();
    }

    /**
     * Requires every metadata/*.php, custom/metadata/*.php, and
     * modules/*\/vardefs.php file to populate $dictionary, then creates any
     * table declared there (with both fields and indices) that doesn't
     * already exist — this catches relationship/join tables and other
     * dictionary-declared tables that aren't a single bean's own table, so
     * wouldn't be created by createMissingModuleTables() below.
     * custom/metadata/*.php is where Studio-created custom relationships'
     * join-table definitions live — see the class docblock.
     */
    private function createMissingDictionaryTables(\DBManager $db, OutputInterface $output): void
    {
        global $dictionary;

        $files = array_merge(
            glob('metadata/*.php') ?: [],
            glob('custom/metadata/*.php') ?: [],
            glob('modules/*/vardefs.php') ?: [],
        );

        foreach ($files as $file) {
            require $file;
        }

        foreach ($dictionary as $definition) {
            if (empty($definition['table']) || empty($definition['fields']) || empty($definition['indices'])) {
                continue;
            }

            if ($db->tableExists($definition['table'])) {
                continue;
            }

            $output->writeln(sprintf('Creating missing SQL table: %s', $definition['table']));
            $db->repairTableParams($definition['table'], $definition['fields'], $definition['indices']);
        }
    }

    private function createMissingModuleTables(\DBManager $db, OutputInterface $output): void
    {
        global $beanList, $app_list_strings;

        $fullModuleList = array_merge($beanList, $app_list_strings['moduleList'] ?? []);

        foreach (array_keys($fullModuleList) as $module) {
            $bean = \BeanFactory::newBean($module);

            if (!$bean instanceof \SugarBean) {
                continue;
            }

            $table = $bean->getTableName();

            if ('' === (string) $table || $db->tableExists($table)) {
                continue;
            }

            $output->writeln(sprintf('Creating missing SQL table: %s', $table));
            $db->createTable($bean);
        }
    }
}
