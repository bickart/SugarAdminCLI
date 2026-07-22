<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * For each single-select enum field on a module, diffs the dropdown options
 * configured in app_list_strings against the values actually present in the
 * column, reporting configured options no record currently uses. Not a
 * stock Sugar report — no equivalent exists.
 *
 * Confirmed live against keecor: $app_list_strings is NOT pre-populated by
 * the Symfony console bootstrap the way it is for a web request or
 * cron.php's own explicit load — it must be loaded here the same way
 * cron.php does (return_app_list_strings_language()), or every dom lookup
 * silently comes back empty.
 *
 * Scoped to 'enum' fields only, not 'multienum' — multienum values are
 * stored as a single caret-delimited packed string (e.g.
 * "^Option1^,^Option2^"), so a plain SELECT DISTINCT can't diff it the same
 * way; that would need its own FIND_IN_SET-style parsing and is treated as
 * a distinct, currently out-of-scope case (skipped with a message so the
 * command output can't be misread as covering multienum fields when it
 * silently didn't).
 *
 * The blank/default `'' => ''` option most dropdowns include is never
 * reported as unused — it represents "not set," not a real option a record
 * would need to select.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class ReportDropdownUsageCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:report:dropdown-usage')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module to check dropdown field usage for')
            ->setDescription('Report configured dropdown options that no record currently uses.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $module = (string) $input->getOption('module');

        if ('' === $module) {
            throw new \RuntimeException('--module is required.');
        }

        $probe = \BeanFactory::newBean($module);

        if (!$probe instanceof \SugarBean) {
            throw new \RuntimeException(sprintf('Unknown module "%s".', $module));
        }

        global $sugar_config;
        $appListStrings = \return_app_list_strings_language($sugar_config['default_language'] ?? 'en_us');

        $db = \DBManagerFactory::getInstance();
        $connection = $db->getConnection();
        $coreColumns = array_keys($db->get_columns($probe->table_name));
        $hasCustomFields = method_exists($probe, 'hasCustomFields') && $probe->hasCustomFields();
        $customTable = $hasCustomFields ? $probe->get_custom_table_name() : null;
        $customColumns = null !== $customTable ? array_keys($db->get_columns($customTable)) : [];

        $checked = 0;
        $totalUnused = 0;

        foreach ($probe->field_defs as $name => $def) {
            if ('enum' !== ($def['type'] ?? '') || empty($def['options'])) {
                continue;
            }

            $domKey = $def['options'];
            $optionList = $appListStrings[$domKey] ?? null;

            if (!is_array($optionList)) {
                $output->writeln(sprintf('%s: dropdown "%s" not found, skipping.', $name, $domKey));

                continue;
            }

            if (in_array($name, $coreColumns, true)) {
                $table = $probe->table_name;
                $joinToCore = false;
            } elseif (in_array($name, $customColumns, true)) {
                $table = $customTable;
                $joinToCore = true;
            } else {
                $output->writeln(sprintf('%s: no matching DB column, skipping.', $name));

                continue;
            }

            ++$checked;

            $builder = $connection->createQueryBuilder();

            if ($joinToCore) {
                $builder->select(sprintf('DISTINCT cstm.%s AS value', $name))
                    ->from($table, 'cstm')
                    ->innerJoin('cstm', $probe->table_name, 'core', 'cstm.id_c = core.id')
                    ->where('core.deleted = 0');
            } else {
                $builder->select(sprintf('DISTINCT %s AS value', $name))
                    ->from($table)
                    ->where('deleted = 0');
            }

            $usedValues = $builder->executeQuery()->fetchFirstColumn();
            $unusedKeys = array_filter(
                array_diff(array_keys($optionList), $usedValues),
                static fn (string $key): bool => '' !== $key,
            );

            if ([] !== $unusedKeys) {
                $totalUnused += count($unusedKeys);
                $output->writeln(sprintf('%s ("%s"): unused option(s): %s', $name, $domKey, implode(', ', $unusedKeys)));
            }
        }

        $output->writeln(sprintf('Checked %d enum field(s), %d unused option(s) total.', $checked, $totalUnused));
    }
}
