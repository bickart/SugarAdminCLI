<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diffs each module's vardef-declared fields against its actual core-table
 * DB columns ($db->get_columns()) — drift at column granularity, pairing
 * with amaiza:admin:repair:missing-tables (whole-table granularity). Not a stock
 * Sugar report — no equivalent exists.
 *
 * Scoped to the core table only, not each module's _cstm table — custom
 * field/DB-column drift there is already covered by
 * amaiza:admin:report:field-usage's own column enumeration. Confirmed via a real
 * bean's field_defs (not guessed from raw vardef file fragments, which are
 * only fragments merged at repair time): a custom field's compiled
 * field_defs entry carries either a 'custom_module' key or
 * 'source' => 'custom_fields' depending on how it was created (confirmed
 * live against keecor: Studio/Module-Builder-created fields use one marker
 * or the other, not consistently the same one — checking only
 * 'custom_module' produced false positives on real custom fields using the
 * other marker) — either one means "lives in _cstm, not this table," so
 * both are skipped. A virtual/relate/link field has 'source' => 'non-db'
 * (skip — no physical column expected at all); anything else is expected to
 * have a real matching column in the core table.
 *
 * Modules whose core table doesn't exist at all are skipped — that's
 * amaiza:admin:repair:missing-tables' job, not this one's.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class ReportSchemaDiffCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:report:schema-diff')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Only check this one module (default: every module)')
            ->setDescription("Diff each module's vardef-declared fields against its actual core-table DB columns.");
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $moduleFilter = (string) $input->getOption('module');

        global $beanList, $app_list_strings;
        $db = \DBManagerFactory::getInstance();

        $modules = '' !== $moduleFilter
            ? [$moduleFilter]
            : array_keys(array_merge($beanList, $app_list_strings['moduleList'] ?? []));

        $checked = 0;
        $totalProblems = 0;
        $processedTables = [];

        foreach ($modules as $module) {
            $bean = \BeanFactory::newBean($module);

            if (!$bean instanceof \SugarBean || isset($processedTables[$bean->table_name])) {
                continue;
            }

            if (!$db->tableExists($bean->table_name)) {
                continue;
            }

            $processedTables[$bean->table_name] = true;
            ++$checked;
            $totalProblems += $this->diffTable($db, $module, $bean, $output);
        }

        $output->writeln(sprintf('Checked %d table(s), %d problem(s) found.', $checked, $totalProblems));
    }

    private function diffTable(\DBManager $db, string $module, \SugarBean $bean, OutputInterface $output): int
    {
        $dbColumns = array_keys($db->get_columns($bean->table_name));
        $expectedColumns = [];

        foreach ($bean->field_defs as $name => $def) {
            if (isset($def['custom_module']) || 'custom_fields' === ($def['source'] ?? '')) {
                continue;
            }

            if ('non-db' === ($def['source'] ?? 'db')) {
                continue;
            }

            $expectedColumns[] = $name;
        }

        $missingFromDb = array_diff($expectedColumns, $dbColumns);
        $orphanedInDb = array_diff($dbColumns, $expectedColumns);
        $problems = 0;

        foreach ($missingFromDb as $field) {
            $output->writeln(sprintf('%s (%s): vardef field "%s" has no matching DB column.', $module, $bean->table_name, $field));
            ++$problems;
        }

        foreach ($orphanedInDb as $column) {
            $output->writeln(sprintf('%s (%s): DB column "%s" has no matching vardef field.', $module, $bean->table_name, $column));
            ++$problems;
        }

        return $problems;
    }
}
