<?php

declare(strict_types=1);

/**
 * Minimal BeanFactory/SugarBean/sugar_cached() fakes for commands that walk
 * a module's field_defs. Kept out of SugarStubs.php on purpose: that file is
 * also phpstan's bootstrap, and partial stubs of these two classes there
 * would replace phpstan's "class not found" (ignored in phpstan.dist.neon)
 * with "method not found" for every other BeanFactory/SugarBean call in the
 * package. Loaded by tests/bootstrap.php only.
 */
class SugarBean {
    /** @var array<string, mixed>|null */
    public ?array $field_defs = [];

    public string $object_name = '';
}

class BeanFactory {
    /** @var array<string, \SugarBean> beans newBean() returns, by module */
    public static array $beans = [];

    /** @var array<string, true> modules whose newBean() throws, like a broken custom bean constructor */
    public static array $throwFor = [];

    public static function newBean(string $module): ?\SugarBean
    {
        if (isset(self::$throwFor[$module])) {
            throw new \RuntimeException("constructor of {$module} failed");
        }

        return self::$beans[$module] ?? null;
    }

    public static function reset(): void
    {
        self::$beans = [];
        self::$throwFor = [];
    }
}

/** Sugar's cache path helper, rooted at a directory each test controls. */
function sugar_cached(string $file): string
{
    return ($GLOBALS['sugarAdminCliStub_cacheDir'] ?? sys_get_temp_dir()).'/'.$file;
}
