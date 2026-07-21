<?php
// Test fixture: a deliberate same-priority collision on after_save, plus a
// before_save hook pointing at a file that doesn't exist on disk.

$hook_array['after_save'][] = [
    1,
    'First hook',
    'custom/include/TestModuleHooks.php',
    'TestModuleHooks',
    'afterSaveOne',
];

$hook_array['after_save'][] = [
    1,
    'Second hook (priority collision)',
    'custom/include/TestModuleHooks.php',
    'TestModuleHooks',
    'afterSaveTwo',
];

$hook_array['before_save'][] = [
    5,
    'Missing file hook',
    'custom/include/DoesNotExist.php',
    'NonexistentClass',
    'someMethod',
];
