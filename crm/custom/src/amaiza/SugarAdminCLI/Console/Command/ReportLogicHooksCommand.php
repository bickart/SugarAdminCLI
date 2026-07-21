<?php
namespace Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Audits custom/modules/*\/Ext/LogicHooks/logichooks.ext.php files (the
 * Extension Model this repo's own CLAUDE.md documents) for two classes of
 * problem CLAUDE.md already tells contributors to check by hand:
 *
 * - Two hooks registered on the same module + event sharing the same
 *   priority integer — undefined execution order between them.
 * - A registered file path that doesn't exist on disk, or (once loaded) a
 *   class/method that doesn't exist — either would fail silently or
 *   fatally the next time that event actually fires, not at scan time.
 *
 * Loads each file's $hook_array via require() inside a fresh local scope per
 * file (a bare local $hook_array before the require, since require shares
 * the caller's scope) so hooks from one module's file never leak into the
 * next file's results. Purely a filesystem/reflection scan — never touches
 * the database. Relies on bin/sugarcrm's own chdir() into the Sugar root
 * (crm/) before any command runs, the same as every other command in this
 * package that reads/requires paths relative to that root.
 *
 * NOT YET LIVE-VERIFIED — see RELEASENOTES.md.
 */
class ReportLogicHooksCommand extends AbstractRepairCommand {
    protected function configure(): void
    {
        $this
            ->setName('admin:report:logic-hooks')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, "Only check this one module's logichooks.ext.php (default: every custom module)")
            ->setDescription('Audit custom logic hooks for same-priority collisions and missing files/classes/methods.');
    }

    protected function repair(InputInterface $input, OutputInterface $output): void
    {
        $moduleFilter = (string) $input->getOption('module');
        $pattern = '' !== $moduleFilter
            ? sprintf('custom/modules/%s/Ext/LogicHooks/logichooks.ext.php', $moduleFilter)
            : 'custom/modules/*/Ext/LogicHooks/logichooks.ext.php';

        $files = glob($pattern);

        if ([] === $files) {
            $output->writeln('No logichooks.ext.php files found.');

            return;
        }

        $problems = 0;

        foreach ($files as $file) {
            $module = basename(dirname($file, 3));
            $hookArray = $this->loadHookArray($file);

            foreach ($hookArray as $event => $entries) {
                $problems += $this->checkMissingHooks($module, (string) $event, $entries, $output);
                $problems += $this->checkPriorityCollisions($module, (string) $event, $entries, $output);
            }
        }

        $output->writeln(0 === $problems ? 'No problems found.' : sprintf('Total problems found: %d', $problems));
    }

    /**
     * @return array<string, list<array{0: int, 1: ?string, 2: ?string, 3: string, 4: ?string}>>
     */
    private function loadHookArray(string $file): array
    {
        $hook_array = [];
        $hook_version = null;
        require $file;

        return $hook_array;
    }

    /**
     * @param list<array{0: int, 1: ?string, 2: ?string, 3: string, 4: ?string}> $entries
     */
    private function checkMissingHooks(string $module, string $event, array $entries, OutputInterface $output): int
    {
        $problems = 0;

        foreach ($entries as $entry) {
            [$priority, $description, $file, $class, $method] = array_pad($entry, 5, null);
            $description ??= '(no description)';

            if (null === $class || '' === $class) {
                continue;
            }

            if (!empty($file) && !file_exists($file)) {
                $output->writeln(sprintf('%s.%s (priority %d, "%s"): file not found: %s', $module, $event, $priority, $description, $file));
                ++$problems;

                continue;
            }

            if (!empty($file)) {
                require_once $file;
            }

            if (!class_exists($class)) {
                $output->writeln(sprintf('%s.%s (priority %d, "%s"): class not found: %s', $module, $event, $priority, $description, $class));
                ++$problems;

                continue;
            }

            if (!empty($method) && !method_exists($class, $method)) {
                $output->writeln(sprintf('%s.%s (priority %d, "%s"): method "%s" not found on %s', $module, $event, $priority, $description, $method, $class));
                ++$problems;
            }
        }

        return $problems;
    }

    /**
     * @param list<array{0: int, 1: ?string, 2: ?string, 3: string, 4: ?string}> $entries
     */
    private function checkPriorityCollisions(string $module, string $event, array $entries, OutputInterface $output): int
    {
        $byPriority = [];

        foreach ($entries as $entry) {
            $byPriority[$entry[0]][] = $entry[1] ?? '(no description)';
        }

        $problems = 0;

        foreach ($byPriority as $priority => $descriptions) {
            if (count($descriptions) > 1) {
                $output->writeln(sprintf(
                    '%s.%s: %d hooks share priority %d: %s',
                    $module,
                    $event,
                    count($descriptions),
                    $priority,
                    implode(', ', $descriptions),
                ));
                ++$problems;
            }
        }

        return $problems;
    }
}
