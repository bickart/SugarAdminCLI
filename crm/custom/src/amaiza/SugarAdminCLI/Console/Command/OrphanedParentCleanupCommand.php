<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use BeanFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Soft-deletes records whose parent_type/parent_id flex-relate points at a
 * record that no longer exists.
 *
 * Not a stock Administration > Repair action — no equivalent exists in Sugar
 * core. Generalizes a customer-specific command (dynatron:clean:tasks, this
 * package's author's own prior work, scoped to just the Tasks module) into a
 * configurable --modules list. Verified directly against Sugar 26's vardefs
 * that only 5 stock modules actually have this field pair as real fields:
 * Tasks, Calls, Meetings, Notes, Emails — Cases and most other modules link
 * to their parent via a fixed FK (e.g. account_id), not this flex-relate
 * pattern, so adding them to --modules would just skip them harmlessly (see
 * the field_defs check below) rather than do anything useful.
 *
 * Uses mark_deleted() — a soft delete, reversible via admin:repair:restore-record.
 * BeanFactory::getBean() excludes soft-deleted-but-not-yet-purged parents by
 * default, so a record whose parent is itself only soft-deleted (not gone
 * entirely) is also treated as "orphaned" here — intentional, matching the
 * original Tasks-only command's behavior, but worth knowing: restoring that
 * parent later won't un-orphan records already cleaned up by this command.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class OrphanedParentCleanupCommand extends AbstractRepairCommand {
    /**
     * @var list<string>
     */
    private const DEFAULT_MODULES = ['Tasks', 'Calls', 'Meetings', 'Notes', 'Emails'];

    protected function configure(): void
    {
        $this
            ->setName('admin:repair:orphaned-parent-cleanup')
            ->addOption(
                'modules',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Comma-separated modules to check (default: %s)', implode(',', self::DEFAULT_MODULES)),
            )
            ->setDescription('Soft-delete records (Tasks, Calls, Meetings, Notes, Emails by default) whose parent record no longer exists.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $modulesOption = (string) $input->getOption('modules');
        $modules = '' !== trim($modulesOption)
            ? array_values(array_filter(array_map('trim', explode(',', $modulesOption))))
            : self::DEFAULT_MODULES;

        foreach ($modules as $module) {
            $this->cleanupModule($module, $output);
        }
    }

    private function cleanupModule(string $module, OutputInterface $output): void
    {
        $probe = \BeanFactory::newBean($module);

        if (!$probe instanceof \SugarBean || !isset($probe->field_defs['parent_type'], $probe->field_defs['parent_id'])) {
            $output->writeln(sprintf('Skipping %s: no parent_type/parent_id field on this module.', $module));

            return;
        }

        $query = new \SugarQuery();
        $query->from($probe);
        $query->select(['id', 'parent_id', 'parent_type']);
        $query->where()->isNotEmpty('parent_type');
        $query->orderBy('parent_type');

        $bean = \BeanFactory::newBean($module);
        $deleted = 0;

        foreach ($query->execute() as $row) {
            if (!$this->parentExists($row['parent_type'] ?? null, $row['parent_id'] ?? null)) {
                $bean->mark_deleted($row['id']);
                ++$deleted;
            }
        }

        $output->writeln(sprintf('%s: soft-deleted %d orphaned record(s).', $module, $deleted));
    }

    private function parentExists(?string $parentType, ?string $parentId): bool
    {
        if (empty($parentType) || empty($parentId)) {
            return false;
        }

        $related = \BeanFactory::getBean($parentType, $parentId, ['disable_row_level_security' => true]);

        return !empty($related->id);
    }
}
