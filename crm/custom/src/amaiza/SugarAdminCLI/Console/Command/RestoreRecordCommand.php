<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restore a soft-deleted record and most of its relationships.
 *
 * Not a stock Administration > Repair action — no equivalent exists in
 * Sugar core. Behavior modeled on esimonetti/toothpaste's
 * local:data:restore-record command (Apache-2.0), reimplemented here
 * directly against Sugar's own bean/relationship APIs rather than reusing
 * that project's code. One-to-many relationships stored as a plain foreign
 * key on the related bean (no join table) can't be restored this way —
 * those need an actual backup, same limitation toothpaste documents.
 *
 * For join-table (many-to-many) relationships, restoring "related records
 * that are themselves soft-deleted while the link to them is still active"
 * requires a direct join-table query — Link2::getBeans(['deleted' => 1])
 * does NOT do this: for M2M relationships it filters on the JOIN ROW's own
 * deleted flag (i.e. whether the *link itself* was removed) via
 * 'add_deleted' => false internally, not on the related bean's deleted
 * column at all (confirmed against data/Relationships/M2MRelationship.php
 * and include/SugarQuery/SugarQuery.php's add_deleted handling). Using it
 * the naive way silently restores nothing in the common case (parent
 * deleted, links never touched) and can wrongly re-link a deliberately
 * removed relationship in the uncommon case (a link was intentionally
 * unlinked via the UI, which soft-deletes the join row).
 */
class RestoreRecordCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:repair:restore-record')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module of the record to restore')
            ->addOption('record', null, InputOption::VALUE_REQUIRED, 'ID of the record to restore')
            ->setDescription('Restore a soft-deleted record (if present) and most of its relationships.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $module = (string) $input->getOption('module');
        $recordId = (string) $input->getOption('record');

        if ('' === $module || '' === $recordId) {
            throw new \RuntimeException('Both --module and --record are required.');
        }

        $mainBean = \BeanFactory::retrieveBean($module, $recordId, ['deleted' => 0]);

        if (null === $mainBean || empty($mainBean->id)) {
            throw new \RuntimeException(sprintf('No record found for module "%s" with id "%s".', $module, $recordId));
        }

        if ($mainBean->deleted) {
            $mainBean->mark_undeleted($mainBean->id);
            $output->writeln(sprintf('Restored main record for "%s" with id "%s".', $module, $mainBean->id));
        }

        $skipped = [];

        foreach ($mainBean->get_linked_fields() as $linkFieldData) {
            $linkField = $linkFieldData['name'];

            if (!$mainBean->load_relationship($linkField)) {
                continue;
            }

            $link = $mainBean->$linkField;
            $relationship = $link->getRelationshipObject();
            $def = $relationship->def;

            if (empty($def['join_table'])) {
                if (!empty($def['rhs_key'])) {
                    $skipped[] = sprintf(
                        'Module "%s" through link field "%s" (stored on field "%s")',
                        $def['rhs_module'],
                        $linkField,
                        $def['rhs_key'],
                    );
                }

                continue;
            }

            $this->restoreSoftDeletedRelatedBeans($mainBean, $link, $def, $output);
        }

        if ([] !== $skipped) {
            $output->writeln('One-to-many, field-based relationships (no join table) cannot be restored without an actual backup:');
            foreach ($skipped as $message) {
                $output->writeln('  - '.$message);
            }
        }
    }

    /**
     * @param array<string, mixed> $def
     */
    private function restoreSoftDeletedRelatedBeans(\SugarBean $mainBean, \Link2 $link, array $def, OutputInterface $output): void
    {
        $relatedModule = $link->getRelatedModuleName();

        if (false === $relatedModule) {
            return;
        }

        $relatedProbe = \BeanFactory::newBean($relatedModule);

        if (!$relatedProbe instanceof \SugarBean) {
            return;
        }

        $onLhs = \REL_LHS === $link->getSide();
        $myKey = $onLhs ? $def['join_key_lhs'] : $def['join_key_rhs'];
        $targetKey = $onLhs ? $def['join_key_rhs'] : $def['join_key_lhs'];

        $connection = \DBManagerFactory::getInstance()->getConnection();
        $relatedIds = $connection->createQueryBuilder()
            ->select('jt.'.$targetKey)
            ->from($def['join_table'], 'jt')
            ->innerJoin('jt', $relatedProbe->table_name, 'rt', sprintf('jt.%s = rt.id', $targetKey))
            ->where(sprintf('jt.%s = :mainId', $myKey))
            ->andWhere('jt.deleted = 0')
            ->andWhere('rt.deleted = 1')
            ->setParameter('mainId', $mainBean->id)
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($relatedIds as $id) {
            $relatedBean = \BeanFactory::retrieveBean($relatedModule, $id, ['deleted' => 0]);

            if (null === $relatedBean || empty($relatedBean->id)) {
                continue;
            }

            $relatedBean->mark_undeleted($relatedBean->id);
            $output->writeln(sprintf('Restored related record: %s "%s".', $relatedModule, $relatedBean->id));
        }
    }
}
