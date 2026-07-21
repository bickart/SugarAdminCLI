#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds a distributable archive of crm/custom/ for manual installation into
 * a customer's Sugar instance. Unlike JobQueue, this product has no bean/UI
 * module, so there's no Module Loader manifest/installdefs to generate here
 * — just a zip of the payload. The customer-facing install story (composer
 * package with a post-install copy step vs. this manual zip) is still being
 * decided — see the "Open question" section of the project plan/README.
 *
 * Usage: php bin/pack.php <version>
 */
$version = $argv[1] ?? null;

if (null === $version || '' === $version) {
    fwrite(STDERR, 'Usage: php bin/pack.php <version>'.PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__);
$buildsDir = $repoRoot.'/builds';

if (!is_dir($buildsDir) && !mkdir($buildsDir, 0o775, true) && !is_dir($buildsDir)) {
    fwrite(STDERR, 'Unable to create builds/ directory.'.PHP_EOL);
    exit(1);
}

$zipPath = sprintf('%s/sugaradmincli_%s.zip', $buildsDir, $version);

if (file_exists($zipPath)) {
    unlink($zipPath);
}

$zip = new \ZipArchive();
if (true !== $zip->open($zipPath, \ZipArchive::CREATE)) {
    fwrite(STDERR, sprintf('Unable to create zip at %s'.PHP_EOL, $zipPath));
    exit(1);
}

$crmRoot = $repoRoot.'/crm';
$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($crmRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::LEAVES_ONLY
);

$fileCount = 0;

/** @var \SplFileInfo $file */
foreach ($iterator as $file) {
    $localPath = 'crm/'.ltrim(str_replace($crmRoot, '', $file->getPathname()), '/');
    $zip->addFile($file->getPathname(), $localPath);
    ++$fileCount;
}

$zip->addFromString('VERSION', $version.PHP_EOL);
$zip->close();

echo sprintf('Wrote %s (%d files).'.PHP_EOL, $zipPath, $fileCount);
