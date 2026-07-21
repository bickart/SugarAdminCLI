<?php
// Fixture stand-in for the real Sugar core file of this name. The real
// RepairAndClear class is provided by tests/Support/SugarStubs.php (loaded
// once at bootstrap); this file only needs to exist so the command's own
// `require_once 'modules/Administration/QuickRepairAndRebuild.php';` (which
// runs against whatever chdir() bootstrap.php sets, i.e. this fixture tree)
// doesn't fail with a missing-file error.
