<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists link fields whose relationship is missing from the relationship
 * cache — the vardef cache and the relationship cache out of step with each
 * other. Not a stock Sugar report — no equivalent exists.
 *
 * Saving a bean that has a 'parent' type field makes
 * SugarBean::update_parent_relationships() call
 * SugarRelationshipFactory::getRelationship() for every link field and pass
 * the result straight to checkParentRelationship(), typed SugarRelationship.
 * getRelationship() returns false for a relationship that isn't cached, so
 * one stale link makes those saves (creates, and updates that change the
 * parent) fail with a TypeError, in the UI, logic hooks and scripts alike.
 * Seen live after a Sugar 26.1 upgrade: 31 of FBSG_AdvancedTrackers'
 * dynamically generated links (one per module, added by a vardef extension)
 * had no cached relationship until the relationship cache was rebuilt.
 * amaiza:admin:repair:relationships is the fix; run this again afterwards to
 * confirm nothing is left.
 *
 * Only modules with a parent field that update_parent_relationships()
 * actually processes (type 'parent' with both type_name and id_name) break
 * on save; elsewhere a missing relationship only fails when that link is
 * used, so each finding says which case it is. A link still listed after a
 * rebuild has no relationship defined anywhere (e.g. one commented out of a
 * metadata file while a vardef still points at it) and needs its definition
 * fixed instead.
 *
 * Exits non-zero when anything is missing, so a deploy pipeline can gate on
 * it. Checks the compiled field_defs of a real bean per module (what save()
 * actually walks), not raw vardef files, once per bean object_name, and asks
 * the same SugarRelationshipFactory singleton save() uses. That singleton
 * builds and writes the cache file itself when it's missing, so a cache built
 * during this run is called out: the result then reflects a fresh cache, not
 * necessarily what web requests were using.
 */
class ReportRelationshipsCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('amaiza:admin:report:relationships')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Only check this one module (default: every module)')
            ->setDescription('List link fields whose relationship is missing from the relationship cache.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $modules = $this->resolveModules((string) $input->getOption('module'));
        $factory = \SugarRelationshipFactory::getInstance();

        $cacheFile = sugar_cached('Relationships/relationships.cache.php');

        if (!is_file($cacheFile) || filemtime($cacheFile) >= (int) ($_SERVER['REQUEST_TIME'] ?? time())) {
            $output->writeln(sprintf(
                'Note: the relationship cache (%s) was built during this run, so this checks a freshly built cache, not necessarily the one web requests were using.',
                $cacheFile,
            ));
        }

        $checkedModules = 0;
        $checkedLinks = 0;
        $missing = 0;
        $missingOnSave = 0;
        $skipped = 0;
        $checkedObjects = [];

        foreach ($modules as $module) {
            try {
                $bean = \BeanFactory::newBean($module);

                if (!$bean instanceof \SugarBean) {
                    continue;
                }

                // Module keys that share a bean (aliases, or the same module in both lists) share its field_defs
                $objectName = '' !== (string) ($bean->object_name ?? '') ? (string) $bean->object_name : $module;

                if (isset($checkedObjects[$objectName])) {
                    continue;
                }

                $checkedObjects[$objectName] = true;

                // Some beans (e.g. ones with disable_vardefs) have no field_defs at all
                $fieldDefs = array_filter(is_array($bean->field_defs) ? $bean->field_defs : [], 'is_array');
                $breaksOnSave = $this->hasProcessedParentField($fieldDefs);
                ++$checkedModules;

                foreach ($fieldDefs as $name => $def) {
                    if ('link' !== ($def['type'] ?? '') || empty($def['relationship'])) {
                        continue;
                    }

                    ++$checkedLinks;

                    if (!$factory->relationshipExists($def['relationship'])) {
                        $output->writeln(sprintf(
                            '%s.%s: relationship "%s" is not in the relationship cache (%s).',
                            $module,
                            $name,
                            $def['relationship'],
                            $breaksOnSave ? 'module has a parent field: saves fail' : 'fails only when this link is used',
                        ));
                        ++$missing;
                        $missingOnSave += $breaksOnSave ? 1 : 0;
                    }
                }
            } catch (\Throwable $exception) {
                $output->writeln(sprintf('%s: skipped, %s: %s', $module, $exception::class, $exception->getMessage()));
                ++$skipped;
            }
        }

        $output->writeln(sprintf(
            'Checked %d link field(s) across %d module(s), %d missing relationship(s), %d in modules whose saves fail%s.',
            $checkedLinks,
            $checkedModules,
            $missing,
            $missingOnSave,
            $skipped > 0 ? sprintf(', %d module(s) skipped', $skipped) : '',
        ));

        if ($missing > 0) {
            $output->writeln('Run amaiza:admin:repair:relationships, then this report again. Anything still listed has no relationship defined at all: fix the link or restore its relationship definition.');
            $this->failAfterOutput(sprintf('%d link field(s) have no relationship in the relationship cache.', $missing));
        }
    }

    /**
     * Mirrors the guard in SugarBean::update_parent_relationships(): a parent
     * field without type_name or id_name is skipped there, so it can't make
     * a save fail.
     *
     * @param array<array<string, mixed>> $fieldDefs
     */
    private function hasProcessedParentField(array $fieldDefs): bool
    {
        foreach ($fieldDefs as $def) {
            if ('parent' === ($def['type'] ?? '') && !empty($def['type_name']) && !empty($def['id_name'])) {
                return true;
            }
        }

        return false;
    }
}
