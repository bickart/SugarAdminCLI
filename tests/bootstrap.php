<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/Support/SugarStubs.php';

// Command classes require Sugar core files by relative path (e.g.
// 'modules/Administration/QuickRepairAndRebuild.php'), same as they would
// against a real Sugar root. chdir into the fixture tree so those resolve
// against fixtures/sugar instead of a live Sugar instance.
chdir(__DIR__.'/fixtures/sugar');
