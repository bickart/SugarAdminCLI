#!/usr/bin/env php
<?php

declare(strict_types=1);

use SugarAdminCLI\Dev\SugarModuleLinker;

require dirname(__DIR__).'/vendor/autoload.php';

$command = $argv[1] ?? 'install';
$useCopy = in_array('--copy', $argv, true) || in_array('-C', $argv, true);
$useLink = in_array('--link', $argv, true) || in_array('-L', $argv, true);
$repoRoot = dirname(__DIR__);

try {
    $linker = SugarModuleLinker::fromConfig($repoRoot);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: '.$e->getMessage().PHP_EOL);
    exit(1);
}

switch ($command) {
    case 'install':
        $useSymlinks = $linker->shouldUseSymlinks($useCopy, $useLink);
        echo ($useSymlinks ? 'Linking' : 'Copying').' SugarAdminCLI commands into Sugar...'.PHP_EOL;
        $result = $linker->install($useSymlinks);
        echo sprintf(
            'Done. %d %s, %d skipped, %d orphan links removed.'.PHP_EOL,
            $result['linked'],
            $useSymlinks ? 'linked' : 'copied',
            $result['skipped'],
            $result['removed']
        );
        echo 'Run "composer sugar:repair" before testing.'.PHP_EOL;
        break;
    case 'uninstall':
        echo 'Removing SugarAdminCLI dev install from Sugar...'.PHP_EOL;
        $result = $linker->uninstall();
        echo sprintf(
            'Done. %d paths removed, %d orphan links removed.'.PHP_EOL,
            $result['removed'],
            $result['orphans']
        );
        break;
    case 'repair':
        $exitCode = $linker->repair();
        if (0 !== $exitCode) {
            fwrite(STDERR, sprintf('Sugar repair failed with exit code %d.'.PHP_EOL, $exitCode));
            exit($exitCode);
        }
        echo 'Repair complete.'.PHP_EOL;
        break;
    default:
        fwrite(STDERR, <<<USAGE
            Usage:
              php bin/sugar-module-install.php install [--copy|-C] [--link|-L]
              php bin/sugar-module-install.php uninstall
              php bin/sugar-module-install.php repair

            Composer aliases:
              composer sugar:module:install
              composer sugar:module:uninstall
              composer sugar:repair

            USAGE);
        exit(1);
}
