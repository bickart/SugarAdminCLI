<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use BeanFactory;
use Doctrine\DBAL\Connection;
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
 * BeanFactory::getBean() itself can throw for Calls/Meetings: Sugar core's
 * RecurringCalendarEvent::retrieve() override unconditionally dereferences
 * a recurring series' master event when repeat_parent_id is set, with no
 * null-check before doing so — if that master event is itself gone
 * (exactly the kind of history this command targets), retrieve() throws a
 * TypeError before the bean is even usable. This happens outside
 * mark_deleted()'s own try/catch, so it's isolated separately around the
 * getBean() call itself, the same way, rather than aborting the batch.
 *
 * A distinct parent_type value that doesn't resolve to any real module at
 * all (e.g. a stray "Account" instead of "Accounts" from old bad data) can
 * never have a valid parent, so every non-deleted record with that
 * parent_type is treated as orphaned too — found live against keecor, where
 * this previously silently skipped those records entirely rather than
 * flagging or deleting them. Since "the type itself is invalid" is a
 * different, broader judgment call than "the type is valid but this one row
 * is missing," it requires its own explicit confirmation
 * (confirmOrSkip()) per distinct invalid parent_type, separate from the
 * command's overall --yes/confirmation gate — declining skips just that
 * group of records rather than aborting the whole run. --dry-run reports
 * these the same way as any other orphan (no prompt, since nothing is
 * deleted).
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
        $this->addBackupDirOption();
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

        $backupPath = $this->resolveBackupPath($input, $dryRun, 'orphaned-parent-cleanup');

        if (null !== $backupPath) {
            $output->writeln(sprintf('Backing up soft-deleted rows to %s', $backupPath));
        }

        foreach ($modules as $module) {
            $this->cleanupModule($module, $input, $output, $dryRun, $backupPath);
        }
    }

    private function cleanupModule(string $module, InputInterface $input, OutputInterface $output, bool $dryRun, ?string $backupPath): void
    {
        $probe = \BeanFactory::newBean($module);

        if (!$probe instanceof \SugarBean || !isset($probe->field_defs['parent_type'], $probe->field_defs['parent_id'])) {
            $output->writeln(sprintf('Skipping %s: no parent_type/parent_id field on this module.', $module));

            return;
        }

        $connection = \DBManagerFactory::getInstance()->getConnection();
        $moduleTable = $probe->table_name;
        $hasCustomFields = method_exists($probe, 'hasCustomFields') && $probe->hasCustomFields();

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
                $invalidIds = $connection->createQueryBuilder()
                    ->select('id')
                    ->from($moduleTable)
                    ->where('deleted = 0')
                    ->andWhere('parent_type = :parentType')
                    ->setParameter('parentType', $parentType)
                    ->executeQuery()
                    ->fetchFirstColumn();

                if ([] === $invalidIds) {
                    continue;
                }

                if ($dryRun) {
                    $output->writeln(sprintf(
                        '%s: would soft-delete %d record(s) with unrecognized parent_type "%s" (not a valid module — can never have a parent).',
                        $module,
                        count($invalidIds),
                        $parentType,
                    ));
                    $deleted += count($invalidIds);

                    continue;
                }

                $proceed = $this->confirmOrSkip($input, $output, sprintf(
                    '%s: %d record(s) have unrecognized parent_type "%s" (not a valid module, so they can never have a valid parent). Treat as orphaned and soft-delete them?',
                    $module,
                    count($invalidIds),
                    $parentType,
                ));

                if (!$proceed) {
                    $output->writeln(sprintf('%s: skipping %d record(s) with parent_type "%s" (declined).', $module, count($invalidIds), $parentType));

                    continue;
                }

                [$typeDeleted, $typeFailed] = $this->softDeleteIds($invalidIds, $module, $moduleTable, $connection, $output, $backupPath, $hasCustomFields, $probe);
                $deleted += $typeDeleted;
                $failed += $typeFailed;

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

            [$typeDeleted, $typeFailed] = $this->softDeleteIds($orphanIds, $module, $moduleTable, $connection, $output, $backupPath, $hasCustomFields, $probe);
            $deleted += $typeDeleted;
            $failed += $typeFailed;
        }

        $output->writeln(match (true) {
            $dryRun => sprintf('%s: would soft-delete %d orphaned record(s).', $module, $deleted),
            $failed > 0 => sprintf('%s: soft-deleted %d orphaned record(s), %d failed (see above).', $module, $deleted, $failed),
            default => sprintf('%s: soft-deleted %d orphaned record(s).', $module, $deleted),
        });
    }

    /**
     * Retrieves and mark_deleted()s each id in $ids, backing up first if
     * $backupPath is set. Shared by both orphan-detection paths (missing
     * parent record, and unrecognized/invalid parent_type) since both end
     * in the same real-bean soft-delete with the same per-record error
     * isolation.
     *
     * @param list<string> $ids
     *
     * @return array{0: int, 1: int} [deleted count, failed count]
     */
    private function softDeleteIds(
        array $ids,
        string $module,
        string $moduleTable,
        Connection $connection,
        OutputInterface $output,
        ?string $backupPath,
        bool $hasCustomFields,
        \SugarBean $probe,
    ): array {
        $deleted = 0;
        $failed = 0;

        foreach ($ids as $id) {
            // BeanFactory::getBean() itself can throw for Calls/Meetings:
            // RecurringCalendarEvent::retrieve() (a Sugar core override for
            // these modules) unconditionally dereferences the recurring
            // series' master event when repeat_parent_id is set, with no
            // null-check before doing so — if that master event is itself
            // gone, retrieve() throws a TypeError before the bean is even
            // usable (confirmed live against keecor: a recurring Call whose
            // master event no longer exists aborted the entire command,
            // since this happens outside the mark_deleted() try/catch
            // below). Isolated here the same way, so one bad retrieve
            // doesn't take down the whole batch.
            try {
                $bean = \BeanFactory::getBean($module, $id);
            } catch (\Throwable $exception) {
                ++$failed;
                $output->writeln(sprintf('%s: failed to retrieve "%s": %s', $module, $id, $exception->getMessage()));

                continue;
            }

            if (!$bean instanceof \SugarBean || empty($bean->id)) {
                continue;
            }

            if (null !== $backupPath) {
                $this->backupRow($connection, $moduleTable, $id, $hasCustomFields, $probe, $backupPath);
            }

            // SugarBean::mark_deleted() unconditionally calls
            // InteractionsHelper::deleteInteraction() before anything else,
            // which — for Calls/Meetings with a dictionary 'interactions'
            // entry (e.g. Calls tracking Accounts.last_call_date on
            // status=Held) — retrieves the parent bean via
            // BeanFactory::retrieveBean() with a non-nullable SugarBean
            // return type. For exactly the records this command targets,
            // that parent is gone, so retrieveBean() returns null and Sugar
            // core throws a TypeError before the delete itself even runs
            // (confirmed live against keecor: every "Held" orphaned Call
            // failed this way). Clearing parent_id/parent_type in memory
            // first makes deleteInteraction() no-op via its own
            // empty($parentId) guard — safe because mark_deleted()'s actual
            // DB write (doMarkDeleted()) never touches these two fields, so
            // nothing is persisted differently; the real stored values are
            // already captured in the backup file if --backup-dir was given.
            $bean->parent_id = null;
            $bean->parent_type = null;

            try {
                $bean->mark_deleted($id);
                ++$deleted;
            } catch (\Throwable $exception) {
                ++$failed;
                $output->writeln(sprintf('%s: failed to delete "%s": %s', $module, $id, $exception->getMessage()));
            }
        }

        return [$deleted, $failed];
    }

    /**
     * Backs up the core table row (plus the _cstm row, if the module has
     * custom fields) for one record before it's soft-deleted. A raw SELECT
     * rather than reusing the just-retrieved bean's own field data, so the
     * backup reflects exactly what's in the database, including custom
     * fields the bean object doesn't surface directly.
     */
    private function backupRow(Connection $connection, string $moduleTable, string $id, bool $hasCustomFields, \SugarBean $probe, string $backupPath): void
    {
        $core = $connection->createQueryBuilder()
            ->select('*')
            ->from($moduleTable)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $core) {
            return;
        }

        $row = ['table' => $moduleTable] + $core;

        if ($hasCustomFields) {
            $custom = $connection->createQueryBuilder()
                ->select('*')
                ->from($probe->get_custom_table_name())
                ->where('id_c = :id')
                ->setParameter('id', $id)
                ->executeQuery()
                ->fetchAssociative();

            if (false !== $custom) {
                $row['custom'] = $custom;
            }
        }

        $this->appendBackupRows($backupPath, [$row]);
    }
}
