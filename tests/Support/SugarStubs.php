<?php

declare(strict_types=1);

/**
 * Lightweight fakes for the Sugar globals/classes the tested command classes
 * reference, so they can run under PHPUnit without a live Sugar instance.
 * Loaded once via tests/bootstrap.php.
 */
namespace Sugarcrm\Sugarcrm\Console\CommandRegistry {
    interface CommandInterface {
    }
}
namespace Sugarcrm\Sugarcrm\Console\CommandRegistry\Mode {
    use Sugarcrm\Sugarcrm\Console\CommandRegistry\CommandInterface;

    interface InstanceModeInterface extends CommandInterface {
    }
}
namespace {
    class RepairAndClear {
        public bool $show_output = true;

        public bool $execute = false;

        /** @var list<string> */
        public array $module_list = [];

        /** @var list<array{actions: mixed, modules: mixed, autoexecute: mixed, show_output: mixed, metadata_sections: mixed}> */
        public static array $repairAndClearAllCalls = [];

        public static int $clearAdditionalCachesCalls = 0;

        /**
         * @param mixed $actions
         * @param mixed $modules
         * @param mixed $autoexecute
         * @param mixed $show_output
         * @param mixed $metadata_sections
         */
        public function repairAndClearAll($actions, $modules, $autoexecute = false, $show_output = true, $metadata_sections = false): void
        {
            self::$repairAndClearAllCalls[] = [
                'actions' => $actions,
                'modules' => $modules,
                'autoexecute' => $autoexecute,
                'show_output' => $show_output,
                'metadata_sections' => $metadata_sections,
            ];
        }

        public function clearAdditionalCaches(): void
        {
            ++self::$clearAdditionalCachesCalls;
        }

        public function clearVardefs(): void
        {
        }

        /**
         * @param array<mixed> $objects
         * @param array<mixed> $skipExtensionsSections
         */
        public function rebuildExtensions(array $objects = [], array $skipExtensionsSections = []): void
        {
        }

        public function clearExternalAPICache(): void
        {
        }

        public function repairDatabase(): void
        {
        }

        public static function reset(): void
        {
            self::$repairAndClearAllCalls = [];
            self::$clearAdditionalCachesCalls = 0;
        }
    }

    class LanguageManager {
        public static int $removeJSLanguageFilesCalls = 0;

        public static int $clearLanguageCacheCalls = 0;

        public static function removeJSLanguageFiles(): void
        {
            ++self::$removeJSLanguageFilesCalls;
        }

        public static function clearLanguageCache(): void
        {
            ++self::$clearLanguageCacheCalls;
        }

        public static function reset(): void
        {
            self::$removeJSLanguageFilesCalls = 0;
            self::$clearLanguageCacheCalls = 0;
        }
    }

    class Configurator {
        /** @var array<string, mixed> */
        public array $config = [];

        public static int $handleOverrideCalls = 0;

        public function __construct()
        {
            $this->config = $GLOBALS['sugar_config'] ?? [];
        }

        public function handleOverride(): void
        {
            $GLOBALS['sugar_config'] = $this->config;
            ++self::$handleOverrideCalls;
        }

        public static function reset(): void
        {
            self::$handleOverrideCalls = 0;
        }
    }

    class SugarRelationshipFactory {
        /** @var list<mixed> */
        public static array $rebuildCacheCalls = [];

        /**
         * @param mixed $modules
         */
        public static function rebuildCache($modules = []): void
        {
            self::$rebuildCacheCalls[] = $modules;
        }

        public static function reset(): void
        {
            self::$rebuildCacheCalls = [];
        }
    }

    if (!function_exists('translate')) {
        function translate(string $key, string $module = ''): string
        {
            return $key;
        }
    }

    if (!function_exists('return_module_language')) {
        /**
         * @return array<string, string>
         */
        function return_module_language(string $language, string $module): array
        {
            return [];
        }
    }

    if (!function_exists('rebuildSprites')) {
        /** @var list<bool> */
        $GLOBALS['sugarAdminCliStub_rebuildSpritesCalls'] = [];

        function rebuildSprites(bool $fromUpgrade = true): void
        {
            $GLOBALS['sugarAdminCliStub_rebuildSpritesCalls'][] = $fromUpgrade;
        }
    }
}
