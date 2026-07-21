<?php
// Fixture stand-in for the real modules/UpgradeWizard/uw_utils.php. The
// rebuildSprites() function is stubbed in tests/Support/SugarStubs.php
// (loaded once at bootstrap) so both PHPUnit and phpstan's bootstrapFiles
// see the same definition; this file only needs to exist so the command's
// `require_once` doesn't fail with a missing-file error.
