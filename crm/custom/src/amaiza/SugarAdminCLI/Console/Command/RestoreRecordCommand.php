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

            $relatedBeans = $mainBean->$linkField->getBeans(['deleted' => 1]);

            if ([] !== $relatedBeans) {
                foreach ($relatedBeans as $relatedBean) {
                    if ($relatedBean->deleted) {
                        $relatedBean->mark_undeleted($relatedBean->id);
                        $output->writeln(sprintf('Restored related record: %s "%s".', $relatedBean->getModuleName(), $relatedBean->id));
                    }

                    $mainBean->$linkField->add($relatedBean->id);
                }

                continue;
            }

            $relationship = $mainBean->$linkField->getRelationshipObject();
            if (empty($relationship->def['join_table']) && !empty($relationship->def['rhs_key'])) {
                $skipped[] = sprintf(
                    'Module "%s" through link field "%s" (stored on field "%s")',
                    $relationship->def['rhs_module'],
                    $linkField,
                    $relationship->def['rhs_key'],
                );
            }
        }

        if ([] !== $skipped) {
            $output->writeln('One-to-many, field-based relationships (no join table) cannot be restored without an actual backup:');
            foreach ($skipped as $message) {
                $output->writeln('  - '.$message);
            }
        }
    }
}
