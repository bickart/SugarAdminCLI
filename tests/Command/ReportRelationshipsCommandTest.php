<?php

declare(strict_types=1);
namespace SugarAdminCLI\Tests\Command;

use PHPUnit\Framework\TestCase;
use Sugarcrm\Sugarcrm\custom\amaiza\SugarAdminCLI\Console\Command\ReportRelationshipsCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Stubbed modules: "Trackers" has one link whose relationship is cached, one
 * whose relationship isn't, and two fields that aren't checked (a non-link
 * field and a link with no relationship). "ParentTrackers" adds a parent
 * field Sugar processes on save, "LooseParentTrackers" one it skips (no
 * type_name/id_name). The relationship cache file is an old, existing file
 * unless a test removes it.
 */
final class ReportRelationshipsCommandTest extends TestCase {
    private string $cacheDir;

    protected function setUp(): void
    {
        \SugarRelationshipFactory::reset();
        \BeanFactory::reset();

        $staleLink = ['name' => 'stale_link', 'type' => 'link', 'relationship' => 'stale_trackers'];

        \BeanFactory::$beans['Trackers'] = $this->bean('Tracker', [
            'name' => ['name' => 'name', 'type' => 'varchar'],
            'accounts_link' => ['name' => 'accounts_link', 'type' => 'link', 'relationship' => 'accounts_trackers'],
            'stale_link' => $staleLink,
            'no_rel_link' => ['name' => 'no_rel_link', 'type' => 'link'],
        ]);
        \BeanFactory::$beans['ParentTrackers'] = $this->bean('ParentTracker', [
            'parent_name' => ['name' => 'parent_name', 'type' => 'parent', 'type_name' => 'parent_type', 'id_name' => 'parent_id'],
            'stale_link' => $staleLink,
        ]);
        \BeanFactory::$beans['LooseParentTrackers'] = $this->bean('LooseParentTracker', [
            'parent_name' => ['name' => 'parent_name', 'type' => 'parent'],
            'stale_link' => $staleLink,
        ]);
        \SugarRelationshipFactory::$relationships = ['accounts_trackers' => true];

        $this->cacheDir = sys_get_temp_dir().'/sugaradmincli-test-'.bin2hex(random_bytes(4));
        mkdir($this->cacheDir.'/Relationships', 0o775, true);
        $cacheFile = $this->cacheDir.'/Relationships/relationships.cache.php';
        file_put_contents($cacheFile, '<?php');
        touch($cacheFile, time() - 3600);
        $GLOBALS['sugarAdminCliStub_cacheDir'] = $this->cacheDir;
        $GLOBALS['beanList'] = [];
        $GLOBALS['app_list_strings'] = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheDir.'/Relationships/relationships.cache.php');
        @rmdir($this->cacheDir.'/Relationships');
        @rmdir($this->cacheDir);
        unset($GLOBALS['sugarAdminCliStub_cacheDir']);
    }

    /**
     * @param array<string, mixed> $fieldDefs
     */
    private function bean(string $objectName, array $fieldDefs): \SugarBean
    {
        $bean = new \SugarBean();
        $bean->object_name = $objectName;
        $bean->field_defs = $fieldDefs;

        return $bean;
    }

    private function buildTester(): CommandTester
    {
        $application = new Application();
        $application->add(new ReportRelationshipsCommand());

        return new CommandTester($application->find('amaiza:admin:report:relationships'));
    }

    public function testFlagsOnlyTheLinkWithAnUncachedRelationshipAndFails(): void
    {
        $tester = $this->buildTester();

        $exitCode = $tester->execute(['--module' => 'Trackers']);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Trackers.stale_link: relationship "stale_trackers" is not in the relationship cache (fails only when this link is used).', $display);
        self::assertStringNotContainsString('accounts_link', $display);
        self::assertStringContainsString('Checked 2 link field(s) across 1 module(s), 1 missing relationship(s), 0 in modules whose saves fail.', $display);
        self::assertStringContainsString('Run amaiza:admin:repair:relationships', $display);
        self::assertStringContainsString('1 link field(s) have no relationship in the relationship cache.', $display);
        self::assertStringNotContainsString('Note:', $display);
    }

    public function testFlagsSaveFailuresInAModuleWithAProcessedParentField(): void
    {
        $tester = $this->buildTester();

        $tester->execute(['--module' => 'ParentTrackers']);
        $display = $tester->getDisplay();

        self::assertStringContainsString('ParentTrackers.stale_link: relationship "stale_trackers" is not in the relationship cache (module has a parent field: saves fail).', $display);
        self::assertStringContainsString('1 missing relationship(s), 1 in modules whose saves fail.', $display);
    }

    public function testAParentFieldSugarSkipsDoesNotCountAsBreakingSaves(): void
    {
        $tester = $this->buildTester();

        $tester->execute(['--module' => 'LooseParentTrackers']);

        self::assertStringContainsString('(fails only when this link is used)', $tester->getDisplay());
        self::assertStringContainsString('1 missing relationship(s), 0 in modules whose saves fail.', $tester->getDisplay());
    }

    public function testSucceedsOnceTheCacheIsRebuilt(): void
    {
        \SugarRelationshipFactory::$relationships['stale_trackers'] = true;
        $tester = $this->buildTester();

        $exitCode = $tester->execute(['--module' => 'Trackers']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Checked 2 link field(s) across 1 module(s), 0 missing relationship(s), 0 in modules whose saves fail.', $display);
        self::assertStringNotContainsString('repair:relationships', $display);
    }

    public function testRejectsAnUnknownModule(): void
    {
        $tester = $this->buildTester();

        $exitCode = $tester->execute(['--module' => 'NoSuchModule']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unknown module "NoSuchModule".', $tester->getDisplay());
    }

    public function testChecksASharedBeanOnceAndSkipsABrokenModuleWithoutLosingTheRest(): void
    {
        // TrackerAlias resolves to the same bean (object_name) as Trackers
        \BeanFactory::$beans['TrackerAlias'] = \BeanFactory::$beans['Trackers'];
        \BeanFactory::$throwFor['BrokenModule'] = true;
        $GLOBALS['beanList'] = ['Trackers' => 'Tracker', 'BrokenModule' => 'Broken', 'TrackerAlias' => 'Tracker', 'ParentTrackers' => 'ParentTracker'];
        $tester = $this->buildTester();

        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(1, preg_match_all('/^Trackers\.stale_link: relationship/m', $display));
        self::assertStringNotContainsString('TrackerAlias.stale_link', $display);
        self::assertStringContainsString('BrokenModule: skipped, RuntimeException: constructor of BrokenModule failed', $display);
        self::assertStringContainsString('ParentTrackers.stale_link', $display);
        self::assertStringContainsString('Checked 3 link field(s) across 2 module(s), 2 missing relationship(s), 1 in modules whose saves fail, 1 module(s) skipped.', $display);
    }

    public function testNotesACacheBuiltDuringThisRun(): void
    {
        unlink($this->cacheDir.'/Relationships/relationships.cache.php');
        $tester = $this->buildTester();

        $tester->execute(['--module' => 'Trackers']);

        self::assertStringContainsString('Note: the relationship cache', $tester->getDisplay());
        self::assertStringContainsString('was built during this run', $tester->getDisplay());
    }
}
