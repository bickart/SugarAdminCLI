<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Equivalent of Administration > Repair > "Remove XSS".
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 *
 * modules/Administration/RepairXSS.php renders only a module-picker
 * template; the actual repair lives in modules/Administration/Async.php's
 * `repairXssExecute` case, driven by a browser-side two-step AJAX flow
 * (refreshEstimate to collect record ids per module, then a save() per
 * id — SugarBean::save() calls cleanBean() internally, which is what
 * actually strips XSS). Rather than faking two rounds of
 * $_REQUEST-driven AJAX dispatch through that file, this command performs
 * the same two primitive operations directly in one pass: enumerate ids
 * per module, then getBean()->save() each one.
 */
class RemoveXssCommand extends AbstractRepairCommand {
    /**
     * Same exclusion list Async.php's refreshEstimate uses for target=all.
     */
    private const HIDDEN_MODULES = ['Activities', 'Home', 'iFrames', 'Calendar', 'Dashboard'];

    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:repair:xss')
            ->setDescription('Remove XSS — removes XSS vulnerabilities from the database.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        global $moduleList;
        require_once 'include/modules.php';

        $repaired = 0;

        foreach ($moduleList as $module) {
            if (\in_array($module, self::HIDDEN_MODULES, true)) {
                continue;
            }

            $probe = \BeanFactory::newBean($module);
            if (empty($probe)) {
                continue;
            }

            $result = $probe->db->query(sprintf('SELECT id FROM %s', $probe->table_name));
            while ($row = $probe->db->fetchByAssoc($result)) {
                $bean = \BeanFactory::getBean($module, $row['id']);
                $bean->new_with_id = false;
                $bean->processed = true;
                $bean->save();
                ++$repaired;
            }
        }

        $output->writeln(sprintf('Processed %d record(s).', $repaired));
    }
}
