<?php

declare(strict_types=1);

namespace Tests\Support\FrameworkIntegrationSpike;

use Atatusoft\Ppphp\Support\Path;

/** Internal planning experiment: does not write output or change production configuration. */
final class LayoutPlanner
{
    /**
     * File mounts name the destination file; directory mounts name a destination directory.
     * @param list<array{path: string, mount: string, kind: 'file'|'directory', resource?: bool}> $roots
     * @param list<string> $runtimeDirectories
     * @param list<string> $templates
     * @return list<array{source: ?string, output: string, operation: string, hash: string, identity: string}>
     */
    public function plan(
        string $projectRoot,
        array $roots,
        array $runtimeDirectories = [],
        array $templates = ['.blade.php', '.view.php'],
    ): array {
        $projectRoot = realpath($projectRoot) ?: throw new \InvalidArgumentException('Missing project root.');
        foreach ($templates as $suffix) {
            if (!in_array($suffix, ['.blade.php', '.view.php'], true)) {
                throw new \InvalidArgumentException('Only explicit template suffixes are opaque PHP.');
            }
        }
        $runtimeDirectories = array_map(fn (string $path): string => $this->normalizeRelative($path), $runtimeDirectories);
        $entries = [];
        $claims = [];
        foreach ($roots as $root) {
            $source = $this->normalizeRelative($root['path']);
            $mount = $this->normalizeRelative($root['mount'], $root['kind'] === 'directory');
            foreach ($claims as $claimed) {
                if (Path::overlaps(strtolower($source), strtolower($claimed))) {
                    throw new \InvalidArgumentException('Ambiguous source/resource ownership.');
                }
            }
            $claims[] = $source;
            $absolute = Path::join($projectRoot, $source);
            if (is_link($absolute) || Path::hasSymlinkAncestor($absolute, $projectRoot)) {
                throw new \InvalidArgumentException('Symlink source roots are not accepted.');
            }
            if ($root['kind'] === 'file') {
                if (!is_file($absolute)) throw new \InvalidArgumentException('Expected a file root.');
                $files = [$absolute];
            } elseif ($root['kind'] === 'directory') {
                if (!is_dir($absolute)) throw new \InvalidArgumentException('Expected a directory root.');
                $files = $this->discoverFiles($absolute);
            } else {
                throw new \InvalidArgumentException('Unknown root kind.');
            }
            foreach ($files as $file) {
                $relative = Path::resolveRelativeTo($file, $projectRoot);
                if ($this->excludePath($relative)) continue;
                $output = $root['kind'] === 'file'
                    ? $mount
                    : ltrim($mount . '/' . Path::resolveRelativeTo($file, $absolute), '/');
                $operation = $this->classify($file, $templates);
                if (($root['resource'] ?? false) && $operation !== 'copy-resource') {
                    throw new \InvalidArgumentException('Opaque roots cannot contain PHP or ++PHP source.');
                }
                if ($operation === 'compile') {
                    if (str_ends_with(strtolower($output), '.ppphp')) {
                        $output = substr($output, 0, -6) . '.php';
                    } elseif (!str_ends_with(strtolower($output), '.php')) {
                        throw new \InvalidArgumentException('Compiled file mounts must name a PHP output.');
                    }
                }
                foreach ($runtimeDirectories as $runtime) {
                    if (Path::contains(strtolower($runtime), strtolower($output))) {
                        throw new \InvalidArgumentException('Runtime directory overlaps copied or compiled source.');
                    }
                }
                $hash = hash_file('sha256', $file);
                if ($hash === false) throw new \RuntimeException('Unreadable source.');
                $entries[] = $this->createEntry($relative, $output, $operation, $hash);
            }
        }
        foreach ($runtimeDirectories as $runtime) {
            $entries[] = $this->createEntry(null, $runtime, 'create-directory', hash('sha256', 'empty-directory'));
        }
        usort($entries, static fn (array $a, array $b): int => strcmp(strtolower($a['output']), strtolower($b['output'])));
        $outputs = [];
        foreach ($entries as $entry) {
            $key = strtolower($entry['output']); // OutputPlanner reserves case-folded destinations on all hosts.
            foreach ($outputs as $claimed => $operation) {
                if ($key === $claimed || Path::overlaps($key, $claimed)) {
                    throw new \InvalidArgumentException('Output path collision or file/directory overlap.');
                }
            }
            $outputs[$key] = $entry['operation'];
        }
        return $entries;
    }

    public function normalizeRelative(string $path, bool $allowEmpty = false): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' && $allowEmpty) return '';
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, ':') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Mounts and source paths must be relative.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Non-canonical path segment.');
            }
        }
        if (strtolower(explode('/', $path)[0]) === '.ppphp') {
            throw new \InvalidArgumentException('Compiler metadata paths are reserved.');
        }
        return $path;
    }

    /** @return list<string> */
    private function discoverFiles(string $directory): array
    {
        $files = [];
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->isLink()) throw new \InvalidArgumentException('Symlink traversal is not accepted.');
            if ($entry->isDir()) {
                $files = [...$files, ...$this->discoverFiles($entry->getPathname())];
            } elseif ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function excludePath(string $path): bool
    {
        foreach (explode('/', strtolower($path)) as $segment) {
            if ($segment === '.env' || str_starts_with($segment, '.env.') || str_ends_with($segment, '.log')
                || in_array($segment, ['storage', 'cache', 'caches', 'logs', 'sessions', '.gitkeep'], true)) return true;
        }
        return false;
    }

    /** @param list<string> $templates */
    private function classify(string $path, array $templates): string
    {
        $sourcePath = $path;
        $path = strtolower($path);
        if (str_ends_with($path, '.ppphp')) return 'compile';
        foreach ($templates as $suffix) {
            if (str_ends_with($path, $suffix)) return 'copy-resource';
        }
        if (str_ends_with($path, '.php')) return 'copy-php';
        if (pathinfo($path, PATHINFO_EXTENSION) === '') {
            $prefix = file_get_contents($sourcePath, length: 256);
            if ($prefix === false) throw new \RuntimeException('Unreadable source.');
            // Extensionless PHP entrypoints (possibly with a shebang), not every extensionless resource.
            if (preg_match('/\A(?:#![^\r\n]*\r?\n)?<\?php\s/', $prefix) === 1) return 'copy-php';
        }
        return 'copy-resource';
    }

    /** @return array{source: ?string, output: string, operation: string, hash: string, identity: string} */
    private function createEntry(?string $source, string $output, string $operation, string $hash): array
    {
        $output = $this->normalizeRelative($output);
        $entry = compact('source', 'output', 'operation', 'hash');
        return [...$entry, 'identity' => hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR))];
    }
}
