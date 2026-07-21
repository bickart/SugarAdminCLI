<?php
// Fixture stand-in for the real modules/ACL/install_actions.php. Records
// that it ran and what $_REQUEST state it saw, so RepairRolesCommandTest
// can assert the command pre-seeds the right request state before
// requiring this file — mirrors claimspay4's proven approach of setting
// $_REQUEST['upgradeWizard']='silent' before requiring the real file.
$GLOBALS['sugarAdminCliTestCalls']['install_actions'][] = [
    'upgradeWizard' => $_REQUEST['upgradeWizard'] ?? null,
];
