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
 * Orphan detection is a single LEFT JOIN ... WHERE core.id IS NULL query per
 * distinct parent_type present in the module (not one BeanFactory::getBean()
 * call per row) — the original per-row version doesn't scale (e.g. 2M Tasks
 * would mean 2M individual bean-retrieval queries).
 *
 * Uses mark_deleted() — a soft delete, reversible via admin:repair:restore-record
 * — which is a real bean save, not a raw SQL UPDATE: it fires
 * before_delete/after_delete logic hooks and cleans up team_sets_modules, so
 * each candidate is retrieved as a real bean first (never delete a blank,
 * un-retrieved bean instance — that would skip the team-set cleanup and run
 * hooks against empty field data). A single record's hook throwing (e.g. a
 * custom workflow/BPM gate blocking deletion) is caught and reported without
 * aborting the rest of the batch.
 *
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report counts without soft-deleting anything, and skip the confirmation prompt')
            ->setDescription('Soft-delete records whose parent record no longer exists.');
        $this->addConfirmationOption();
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $modulesOption = (string) $input->getOption('modules');
        $modules = '' !== trim($modulesOption)
            ? array_values(array_filter(array_map('trim', explode(',', $modulesOption))))
            : self::DEFAULT_MODULES;
        $dryRun = (bool) $input->getOption('dry-run');

        $output->writeln(sprintf('Checking module(s): %s', implode(', ', $modules)));

        if ($dryRun) {
            $output->writeln('Dry run — reporting counts only, nothing will be soft-deleted.');
        } else {
            $this->confirmDestructiveAction(
                $input,
                $output,
                'This soft-deletes orphaned records (reversible via admin:repair:restore-record, but a real mutation to your data).',
            );
        }

        foreach ($modules as $module) {
            $this->cleanupModule($module, $output, $dryRun);
        }
    }

    private function cleanupModule(string $module, OutputInterface $output, bool $dryRun): void
    {
        $probe = \BeanFactory::newBean($module);

        if (!$probe instanceof \SugarBean || !isset($probe->field_defs['parent_type'], $probe->field_defs['parent_id'])) {
            $output->writeln(sprintf('Skipping %s: no parent_type/parent_id field on this module.', $module));

            return;
        }

        $connection = \DBManagerFactory::getInstance()->getConnection();
        $moduleTable = $probe->table_name;

        $parentTypes = $connection->createQueryBuilder()
            ->select('DISTINCT parent_type')
            ->from($moduleTable)
            ->where('deleted = 0')
            ->andWhere('parent_type IS NOT NULL')
            ->andWhere("parent_type != ''")
            ->executeQuery()
            ->fetchFirstColumn();

        $deleted = 0;
        $failed = 0;

        foreach ($parentTypes as $parentType) {
            $parentBean = \BeanFactory::newBean($parentType);

            if (!$parentBean instanceof \SugarBean) {
                $output->writeln(sprintf('%s: skipping unknown parent_type "%s".', $module, $parentType));

                continue;
            }

            $orphanIds = $connection->createQueryBuilder()
                ->select('src.id')
                ->from($moduleTable, 'src')
                ->leftJoin('src', $parentBean->table_name, 'core', 'src.parent_id = core.id')
                ->where('src.deleted = 0')
                ->andWhere('src.parent_type = :parentType')
                ->andWhere('core.id IS NULL')
                ->setParameter('parentType', $parentType)
                ->executeQuery()
                ->fetchFirstColumn();

            if ([] === $orphanIds) {
                continue;
            }

            if ($dryRun) {
                $output->writeln(sprintf('%s: would soft-delete %d record(s) with missing %s parent.', $module, count($orphanIds), $parentType));
                $deleted += count($orphanIds);

                continue;
            }

            foreach ($orphanIds as $id) {
                $bean = \BeanFactory::getBean($module, $id);

                if (!$bean instanceof \SugarBean || empty($bean->id)) {
                    continue;
                }

                try {
                    $bean->mark_deleted($id);
                    ++$deleted;
                } catch (\Throwable $exception) {
                    ++$failed;
                    $output->writeln(sprintf('%s: failed to delete "%s": %s', $module, $id, $exception->getMessage()));
                }
            }
        }

        $output->writeln(match (true) {
            $dryRun => sprintf('%s: would soft-delete %d orphaned record(s).', $module, $deleted),
            $failed > 0 => sprintf('%s: soft-deleted %d orphaned record(s), %d failed (see above).', $module, $deleted, $failed),
            default => sprintf('%s: soft-deleted %d orphaned record(s).', $module, $deleted),
        });
    }
}
