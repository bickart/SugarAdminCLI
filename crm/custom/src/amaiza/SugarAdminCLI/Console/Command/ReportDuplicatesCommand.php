<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use SugarBean;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Finds — but does not merge — likely duplicate records within a module, by
 * calling Sugar's own per-record SugarBean::findDuplicates() (data/SugarBean.php:8339)
 * for every record in a bounded batch. There is no bulk/module-wide "scan
 * for duplicates" API in Sugar core; this command builds that by iterating
 * records itself.
 *
 * Only modules with a 'duplicate_check' vardef entry support this at all
 * (Accounts/Leads/Contacts out of the box, confirmed by grepping
 * modules/*\/vardefs.php) — any other module errors clearly rather than
 * silently returning nothing.
 *
 * findDuplicates() delegates to BeanDuplicateCheck -> (typically)
 * FilterDuplicateCheck::findDuplicates(), which calls the real Filter API
 * (data/duplicatecheck/FilterDuplicateCheck.php) and therefore needs
 * $GLOBALS['current_user'] set (used by RestService/ACL checks the same way
 * a real API request would be) — set here the same way cron.php does if a
 * console bootstrap hasn't already populated it.
 *
 * Deliberately find-only: Sugar has no server-side "merge two records" API
 * at all (the UI wizard does it in 3 JS-orchestrated steps: save winner
 * fields, relate the loser's relationships onto the winner, delete the
 * loser) — building an unreviewed from-scratch relationship-reassignment
 * implementation is out of scope for this command and tracked as separate,
 * future work.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class ReportDuplicatesCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:report:duplicates')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module to scan for duplicates (only modules with duplicate_check enabled — Accounts/Leads/Contacts out of the box)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of records to scan (default: 500)', '500')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Offset into the module\'s records to start scanning from (default: 0)', '0')
            ->setDescription('Find likely duplicate records within a module (find only — no merge).');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $module = (string) $input->getOption('module');
        $limit = (int) $input->getOption('limit');
        $offset = (int) $input->getOption('offset');

        if ('' === $module) {
            throw new \RuntimeException('--module is required.');
        }

        if ($limit <= 0) {
            throw new \RuntimeException('--limit must be a positive integer.');
        }

        $probe = \BeanFactory::newBean($module);

        if (!$probe instanceof \SugarBean) {
            throw new \RuntimeException(sprintf('Unknown module "%s".', $module));
        }

        if (empty($GLOBALS['dictionary'][$probe->object_name]['duplicate_check']['enabled'])) {
            throw new \RuntimeException(sprintf(
                '"%s" has no duplicate_check vardef enabled — out of the box, only Accounts/Leads/Contacts support this.',
                $module,
            ));
        }

        if (empty($GLOBALS['current_user']) || empty($GLOBALS['current_user']->id)) {
            $systemUser = \BeanFactory::newBean('Users');
            $systemUser->getSystemUser();
            $GLOBALS['current_user'] = $systemUser;
        }

        $connection = \DBManagerFactory::getInstance()->getConnection();
        $ids = $connection->createQueryBuilder()
            ->select('id')
            ->from($probe->table_name)
            ->where('deleted = 0')
            ->orderBy('id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();

        if ([] === $ids) {
            $output->writeln(sprintf('No records found in "%s" at offset %d.', $module, $offset));

            return;
        }

        $output->writeln(sprintf('Scanning %d record(s) of "%s" (offset %d) for duplicates...', count($ids), $module, $offset));

        $reportedPairs = [];
        $pairsFound = 0;

        foreach ($ids as $id) {
            $bean = \BeanFactory::getBean($module, $id);

            if (!$bean instanceof \SugarBean || empty($bean->id)) {
                continue;
            }

            $result = $bean->findDuplicates();
            $records = $result['records'] ?? [];

            foreach ($records as $duplicate) {
                $duplicateId = $duplicate['id'] ?? null;

                if (null === $duplicateId || $duplicateId === $bean->id) {
                    continue;
                }

                $pairKey = implode('|', [min($bean->id, $duplicateId), max($bean->id, $duplicateId)]);

                if (isset($reportedPairs[$pairKey])) {
                    continue;
                }

                $reportedPairs[$pairKey] = true;
                ++$pairsFound;

                $output->writeln(sprintf(
                    '"%s" (%s) <-> "%s" (%s)',
                    $bean->name,
                    $bean->id,
                    $duplicate['name'] ?? 'n/a',
                    $duplicateId,
                ));
            }
        }

        $output->writeln(sprintf('Total duplicate pair(s) found: %d', $pairsFound));
    }
}
