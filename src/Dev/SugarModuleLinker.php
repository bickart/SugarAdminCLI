<?php

declare(strict_types=1);
namespace SugarAdminCLI\Dev;

/**
 * Symlink (or copy) SugarAdminCLI's crm/custom assets into a local Sugar
 * instance for development. Adapted from JobQueue's linker of the same
 * name/shape — see that repo if this one needs updating to match a fix
 * made there first.
 */
final class SugarModuleLinker {
    /**
     * Paths under crm/ that may be linked into Sugar.
     * custom/src/amaiza/SugarAdminCLI is a product-scoped namespace
     * directory exclusively ours (matching JobQueue's own custom/src/amaiza
     * convention, one level more specific to avoid collisions between
     * Amaiza products that both want e.g. a Console/Command folder), so
     * it's safe to link as a whole directory. custom/Extension/application/Ext/Console
     * is still listed file-by-file: it's a shared directory any Sugar
     * instance may already have other, unrelated command registrations in
     * (confirmed against a real instance) — linking it wholesale would
     * delete and replace whatever else already lives there. Never list
     * bare "custom" — see BLOCKED_TARGET_PREFIXES.
     *
     * @var list<string>
     */
    private const SCAFFOLD_ROOTS = [
        'custom/src/amaiza/SugarAdminCLI',
        'custom/Extension/application/Ext/Console/RegisterSugarAdminCliCommands.php',
    ];

    private const BLOCKED_TARGET_PREFIXES = [
        'modules',
        'custom',
    ];
    private const INSTALL_MARKER_BASENAME = '.amaiza-sugaradmincli-dev-install.json';

    private readonly string $crmRoot;

    private readonly string $sugarRoot;

    public function __construct(
        private readonly string $repoRoot,
        string $crmRoot,
        string $sugarRoot,
        private readonly string $sugarRepairCommand = 'sugar_repair',
        private readonly bool $defaultUseSymlinks = false,
        private readonly bool $verbose = true,
    ) {
        $resolvedCrmRoot = realpath($crmRoot);
        $this->crmRoot = false !== $resolvedCrmRoot ? $resolvedCrmRoot : $crmRoot;

        $resolvedSugarRoot = realpath($sugarRoot);
        $this->sugarRoot = false !== $resolvedSugarRoot ? $resolvedSugarRoot : $sugarRoot;
    }

    public static function fromConfig(string $repoRoot, bool $verbose = true): self
    {
        $configFile = $repoRoot.'/sugar.env.php';
        if (!is_readable($configFile)) {
            throw new \RuntimeException(
                'Missing sugar.env.php. Copy sugar.env.php.dist to sugar.env.php and set sugar_root to your Sugar instance.'
            );
        }

        /** @var array<string, mixed> $config */
        $config = require $configFile;
        $sugarRoot = trim((string) ($config['sugar_root'] ?? ''));

        if ('' === $sugarRoot) {
            throw new \RuntimeException('sugar.env.php must define sugar_root.');
        }

        $resolvedSugarRoot = realpath($sugarRoot);
        if (false === $resolvedSugarRoot || !is_dir($resolvedSugarRoot)) {
            throw new \RuntimeException(sprintf('Sugar root does not exist: %s', $sugarRoot));
        }

        $crmRoot = realpath($repoRoot.'/crm');
        if (false === $crmRoot || !is_dir($crmRoot)) {
            throw new \RuntimeException('Expected crm/ directory was not found in the repository.');
        }

        $sugarRepairCommand = trim((string) ($config['sugar_repair_command'] ?? 'sugar_repair'));
        if ('' === $sugarRepairCommand) {
            $sugarRepairCommand = 'sugar_repair';
        }

        $defaultUseSymlinks = (bool) ($config['use_symlinks'] ?? false);

        return new self($repoRoot, $crmRoot, $resolvedSugarRoot, $sugarRepairCommand, $defaultUseSymlinks, $verbose);
    }

    public function shouldUseSymlinks(bool $copyFlag, bool $linkFlag): bool
    {
        if ($copyFlag) {
            return false;
        }

        if ($linkFlag) {
            return true;
        }

        return $this->defaultUseSymlinks;
    }

    public function getSugarRoot(): string
    {
        return $this->sugarRoot;
    }

    /**
     * @return array{linked:int, skipped:int, removed:int}
     */
    public function install(bool $useSymlinks = true): array
    {
        $linked = 0;
        $skipped = 0;

        foreach ($this->getScaffoldItems() as $item) {
            $target = $this->getTargetPath($item);

            if (file_exists($target) || is_link($target)) {
                if ($useSymlinks && is_link($target) && $this->isManagedLink($target)) {
                    $this->log(sprintf('  · Existing link: %s', $this->getRelativeTarget($target)));
                    ++$skipped;
                    continue;
                }

                $this->removePath($target);
            }

            $this->ensureParentDirectory($target);
            $this->createLink($item->getPathname(), $target, $useSymlinks);
            $this->log(sprintf('  + %s: %s', $useSymlinks ? 'Linked' : 'Copied', $this->getRelativeTarget($target)));
            ++$linked;
        }

        $removed = \count($this->cleanupOrphanLinks());
        $this->writeInstallMarker($useSymlinks);

        return [
            'linked' => $linked,
            'skipped' => $skipped,
            'removed' => $removed,
        ];
    }

    /**
     * @return array{removed:int, orphans:int}
     */
    public function uninstall(): array
    {
        $removed = 0;
        $marker = $this->readInstallMarker();

        if (null !== $marker) {
            $removed += $this->uninstallFromMarker($marker);
            $this->removeInstallMarker();
        } else {
            $removed += $this->uninstallLegacySymlinks();
            $removed += $this->uninstallLegacyCopies();
        }

        foreach (self::BLOCKED_TARGET_PREFIXES as $blockedPrefix) {
            $target = $this->sugarRoot.'/'.$blockedPrefix;
            if (is_link($target) && $this->isManagedLink($target)) {
                unlink($target);
                $this->log(sprintf('  - Removed unsafe link: %s', $blockedPrefix));
                ++$removed;
            }
        }

        $orphans = \count($this->cleanupOrphanLinks());

        return [
            'removed' => $removed,
            'orphans' => $orphans,
        ];
    }

    /**
     * @return list<string>
     */
    public function cleanupOrphanLinks(): array
    {
        $removed = [];
        $crmRoot = $this->crmRoot;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sugarRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if (!$item->isLink()) {
                continue;
            }

            $linkTarget = $item->getLinkTarget();
            if (false === $linkTarget) {
                continue;
            }

            $resolvedTarget = realpath($item->getPathname()) ?: $linkTarget;
            if (!str_starts_with($resolvedTarget, $crmRoot)) {
                continue;
            }

            if (!file_exists($item->getPathname()) && !is_link($item->getPathname())) {
                continue;
            }

            if (is_link($item->getPathname()) && false === $item->getRealPath()) {
                unlink($item->getPathname());
                $removed[] = $item->getPathname();
                $this->log(sprintf('  · Removed orphan link: %s', $this->getRelativeTarget($item->getPathname())));
            }
        }

        return $removed;
    }

    public function runSugarRepair(): int
    {
        $command = sprintf(
            '%s %s',
            escapeshellarg($this->sugarRepairCommand),
            escapeshellarg($this->sugarRoot)
        );

        $this->log(sprintf('Running Quick Repair and Rebuild: %s %s', $this->sugarRepairCommand, $this->sugarRoot));
        passthru($command, $exitCode);

        return (int) $exitCode;
    }

    public function repair(): int
    {
        return $this->runSugarRepair();
    }

    /**
     * @return list<\SplFileInfo>
     */
    private function getScaffoldItems(): array
    {
        $items = [];

        foreach (self::SCAFFOLD_ROOTS as $root) {
            $sourcePath = $this->crmRoot.'/'.$root;
            if (file_exists($sourcePath)) {
                $items[] = new \SplFileInfo($sourcePath);
            }
        }

        return $items;
    }

    private function assertSafeTarget(string $relativeTarget): void
    {
        if (\in_array($relativeTarget, self::BLOCKED_TARGET_PREFIXES, true)) {
            throw new \RuntimeException(sprintf('Refusing to link unsafe Sugar path: %s', $relativeTarget));
        }
    }

    private function getTargetPath(\SplFileInfo $item): string
    {
        $relativeTarget = $this->getRelativeSource($item->getPathname());
        $this->assertSafeTarget($relativeTarget);

        return $this->sugarRoot.'/'.$relativeTarget;
    }

    private function getRelativeSource(string $absolutePath): string
    {
        return ltrim(str_replace($this->crmRoot.'/', '', $absolutePath), '/');
    }

    private function getRelativeTarget(string $absolutePath): string
    {
        return ltrim(str_replace($this->sugarRoot.'/', '', $absolutePath), '/');
    }

    private function isManagedLink(string $path): bool
    {
        if (!is_link($path)) {
            return false;
        }

        $target = realpath($path);
        if (false === $target) {
            return false;
        }

        $crmRoot = realpath($this->crmRoot) ?: $this->crmRoot;

        return str_starts_with($target, $crmRoot.\DIRECTORY_SEPARATOR) ||
            $target === $crmRoot;
    }

    /**
     * @param array<string, mixed> $marker
     */
    private function uninstallFromMarker(array $marker): int
    {
        $removed = 0;
        $mode = (string) ($marker['mode'] ?? 'symlink');
        $paths = $marker['paths'] ?? [];

        if (!\is_array($paths)) {
            return 0;
        }

        foreach ($paths as $relativePath) {
            if (!\is_string($relativePath) || '' === $relativePath) {
                continue;
            }

            $target = $this->sugarRoot.'/'.ltrim($relativePath, '/');
            if (!file_exists($target) && !is_link($target)) {
                continue;
            }

            if ('copy' !== $mode && !is_link($target)) {
                continue;
            }

            if (is_link($target) && !$this->isManagedLink($target)) {
                continue;
            }

            $this->removePath($target);
            $this->log(sprintf('  - Removed %s: %s', 'copy' === $mode ? 'copy' : 'link', $this->getRelativeTarget($target)));
            ++$removed;
        }

        return $removed;
    }

    private function uninstallLegacySymlinks(): int
    {
        $removed = 0;

        foreach ($this->getScaffoldItems() as $item) {
            $target = $this->getTargetPath($item);

            if (!is_link($target) || !$this->isManagedLink($target)) {
                continue;
            }

            unlink($target);
            $this->log(sprintf('  - Removed link: %s', $this->getRelativeTarget($target)));
            ++$removed;
        }

        return $removed;
    }

    private function uninstallLegacyCopies(): int
    {
        $removed = 0;

        foreach ($this->getScaffoldItems() as $item) {
            $target = $this->getTargetPath($item);

            if (is_link($target) || (!file_exists($target) && !is_dir($target))) {
                continue;
            }

            if (!$this->isManagedCopy($target)) {
                continue;
            }

            $this->removePath($target);
            $this->log(sprintf('  - Removed copy: %s', $this->getRelativeTarget($target)));
            ++$removed;
        }

        return $removed;
    }

    private function isManagedCopy(string $target): bool
    {
        if (is_link($target)) {
            return false;
        }

        $relativeTarget = $this->getRelativeTarget($target);
        $source = $this->crmRoot.'/'.$relativeTarget;

        if (!file_exists($source)) {
            return false;
        }

        if (is_file($source)) {
            return is_file($target) &&
                hash_file('sha256', $source) === hash_file('sha256', $target);
        }

        if (!is_dir($source) || !is_dir($target)) {
            return false;
        }

        return $this->directoryMatchesSource($source, $target);
    }

    private function directoryMatchesSource(string $source, string $target): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $checked = 0;

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $relativePath = $iterator->getSubPathname();
            $sourceFile = $source.'/'.$relativePath;
            $targetFile = $target.'/'.$relativePath;

            if (!is_file($targetFile)) {
                return false;
            }

            if (hash_file('sha256', $sourceFile) !== hash_file('sha256', $targetFile)) {
                return false;
            }

            ++$checked;
        }

        return $checked > 0;
    }

    private function writeInstallMarker(bool $useSymlinks): void
    {
        $paths = [];

        foreach ($this->getScaffoldItems() as $item) {
            $paths[] = $this->getRelativeSource($item->getPathname());
        }

        $payload = [
            'mode' => $useSymlinks ? 'symlink' : 'copy',
            'repo_root' => $this->repoRoot,
            'paths' => $paths,
            'installed_at' => date('c'),
        ];

        file_put_contents(
            $this->getInstallMarkerPath(),
            json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES).\PHP_EOL
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readInstallMarker(): ?array
    {
        $markerPath = $this->getInstallMarkerPath();
        if (!is_readable($markerPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($markerPath), true);

        return \is_array($decoded) ? $decoded : null;
    }

    private function removeInstallMarker(): void
    {
        $markerPath = $this->getInstallMarkerPath();
        if (file_exists($markerPath)) {
            unlink($markerPath);
        }
    }

    private function getInstallMarkerPath(): string
    {
        return $this->sugarRoot.'/'.self::INSTALL_MARKER_BASENAME;
    }

    private function ensureParentDirectory(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
    }

    private function createLink(string $source, string $target, bool $useSymlinks): void
    {
        if ($useSymlinks) {
            if (!symlink($source, $target)) {
                throw new \RuntimeException(sprintf('Unable to create symlink: %s', $target));
            }

            return;
        }

        if (is_dir($source)) {
            $this->copyDirectory($source, $target);

            return;
        }

        if (!copy($source, $target)) {
            throw new \RuntimeException(sprintf('Unable to copy file: %s', $target));
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
            throw new \RuntimeException(sprintf('Unable to create directory: %s', $target));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $destination = $target.'/'.$iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0o775, true) && !is_dir($destination)) {
                    throw new \RuntimeException(sprintf('Unable to create directory: %s', $destination));
                }
                continue;
            }

            $this->ensureParentDirectory($destination);
            if (!copy($item->getPathname(), $destination)) {
                throw new \RuntimeException(sprintf('Unable to copy file: %s', $destination));
            }
        }
    }

    private function removePath(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }

        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    unlink($item->getPathname());
                } elseif ($item->isDir()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }

            rmdir($path);

            return;
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function log(string $message): void
    {
        if ($this->verbose) {
            echo $message.\PHP_EOL;
        }
    }
}
