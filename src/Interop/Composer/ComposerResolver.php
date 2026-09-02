<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Support\Path;

final class ComposerResolver
{
    /** @param list<string> $excludedProjectRoots */
    public function resolve(string $projectRoot, array $excludedProjectRoots = []): ComposerResolutionResult
    {
        $diagnostics = new DiagnosticBag();
        $configurationPath = Path::join($projectRoot, 'composer.json');

        if (!file_exists($configurationPath)) {
            return new ComposerResolutionResult(new ComposerProject(
                $projectRoot,
                null,
                Path::join($projectRoot, 'vendor'),
                new AutoloadMap(),
                new AutoloadMap(),
            ), $diagnostics);
        }

        $configuration = $this->readObject(
            $configurationPath,
            DiagnosticCode::InvalidComposerConfiguration,
            $diagnostics,
        );

        if ($configuration === null) {
            return new ComposerResolutionResult(null, $diagnostics);
        }

        $projectMaps = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            if (!array_key_exists($section, $configuration)) {
                continue;
            }

            if (!is_array($configuration[$section])) {
                $this->addInvalidAutoloadDiagnostic($diagnostics, sprintf('Composer property "%s" must be an object.', $section));
                continue;
            }

            $map = $this->parseAutoload($configuration[$section], $projectRoot, $diagnostics);

            if ($map !== null) {
                $projectMaps[] = $this->filterExcludedPaths($map, $excludedProjectRoots);
            }
        }

        $extra = $configuration['extra'] ?? null;
        $ppphpExtra = is_array($extra) ? ($extra['ppphp'] ?? null) : null;

        if ($extra !== null && !is_array($extra)) {
            $this->addInvalidAutoloadDiagnostic($diagnostics, 'Composer property "extra" must be an object.');
        } elseif ($ppphpExtra !== null) {
            if (!is_array($ppphpExtra)) {
                $this->addInvalidAutoloadDiagnostic($diagnostics, 'Composer property "extra.ppphp" must be an object.');
            } else {
                foreach (['source-autoload', 'source-autoload-dev'] as $section) {
                    if (!array_key_exists($section, $ppphpExtra)) {
                        continue;
                    }

                    if (!is_array($ppphpExtra[$section])) {
                        $this->addInvalidAutoloadDiagnostic($diagnostics, sprintf('Composer property "extra.ppphp.%s" must be an object.', $section));
                        continue;
                    }

                    $map = $this->parseAutoload($ppphpExtra[$section], $projectRoot, $diagnostics);

                    if ($map !== null) {
                        $projectMaps[] = $this->filterExcludedPaths($map, $excludedProjectRoots);
                    }
                }
            }
        }

        $vendorPath = Path::join($projectRoot, 'vendor');

        if (isset($configuration['config'])) {
            if (!is_array($configuration['config'])) {
                $this->addInvalidAutoloadDiagnostic($diagnostics, 'Composer property "config" must be an object.');
            } elseif (array_key_exists('vendor-dir', $configuration['config'])) {
                $vendorDirectory = $configuration['config']['vendor-dir'];

                if (!is_string($vendorDirectory) || $vendorDirectory === '') {
                    $this->addInvalidAutoloadDiagnostic($diagnostics, 'Composer property "config.vendor-dir" must be a non-empty string.');
                } else {
                    $vendorPath = Path::resolveAbsolute($vendorDirectory, $projectRoot);
                }
            }
        }

        $dependencies = $this->resolveInstalledPackages($vendorPath, $diagnostics);

        if ($diagnostics->hasErrors) {
            return new ComposerResolutionResult(null, $diagnostics);
        }

        return new ComposerResolutionResult(new ComposerProject(
            $projectRoot,
            $configurationPath,
            $vendorPath,
            $this->merge($projectMaps),
            $this->merge(array_map(
                static fn (ComposerPackage $package): AutoloadMap => $package->autoload,
                $dependencies,
            )),
            $dependencies,
            $this->fileIdentity(Path::join($projectRoot, 'composer.lock')),
            $this->fileIdentity(Path::join($vendorPath, 'composer/installed.json')),
        ), $diagnostics);
    }

    /** @param list<string> $excludedRoots */
    private function filterExcludedPaths(AutoloadMap $map, array $excludedRoots): AutoloadMap
    {
        if ($excludedRoots === []) {
            return $map;
        }

        $isIncluded = static function (string $path) use ($excludedRoots): bool {
            foreach ($excludedRoots as $root) {
                if (Path::contains($root, $path)) {
                    return false;
                }
            }

            return true;
        };
        $psr4 = [];
        /** @var array<string, list<string>> $psr0 */
        $psr0 = [];

        foreach ($map->psr4 as $prefix => $paths) {
            $included = array_values(array_filter($paths, $isIncluded));

            if ($included !== []) {
                $psr4[$prefix] = $included;
            }
        }

        foreach ($map->psr0 as $prefix => $paths) {
            $included = array_values(array_filter($paths, $isIncluded));

            if ($included !== []) {
                $psr0[$prefix] = $included;
            }
        }

        return new AutoloadMap(
            psr4: $psr4,
            classmap: array_values(array_filter($map->classmap, $isIncluded)),
            files: array_values(array_filter($map->files, $isIncluded)),
            psr0: $psr0,
            excludeFromClassmap: $map->excludeFromClassmap,
        );
    }

    /**
     * @param array<mixed, mixed> $autoload
     */
    private function parseAutoload(
        array $autoload,
        string $base,
        DiagnosticBag $diagnostics,
        DiagnosticCode $invalidCode = DiagnosticCode::InvalidComposerAutoloadMapping,
        bool $expandClassmapDirectories = true,
    ): ?AutoloadMap {
        /** @var array<string, list<string>> $psr4 */
        $psr4 = [];
        /** @var array<string, list<string>> $psr0 */
        $psr0 = [];
        /** @var list<string> $classmap */
        $classmap = [];
        /** @var list<string> $files */
        $files = [];
        /** @var list<string> $excludeFromClassmap */
        $excludeFromClassmap = [];

        if (isset($autoload['psr-4'])) {
            if (
                !is_array($autoload['psr-4'])
                || ($autoload['psr-4'] !== [] && array_is_list($autoload['psr-4']))
            ) {
                $this->addInvalidAutoloadDiagnostic($diagnostics, 'Composer property "autoload.psr-4" must be an object.', $invalidCode);

                return null;
            }

            foreach ($autoload['psr-4'] as $prefix => $paths) {
                if (
                    !is_string($prefix)
                    || ($prefix !== '' && !str_ends_with($prefix, '\\'))
                    || (!is_string($paths) && !is_array($paths))
                    || (is_array($paths) && !array_is_list($paths))
                ) {
                    $this->addInvalidAutoloadDiagnostic($diagnostics, 'Every Composer PSR-4 mapping must use an empty or namespace-separator-terminated prefix and contain a string path or list of string paths.', $invalidCode);

                    return null;
                }

                $pathList = is_string($paths) ? [$paths] : $paths;
                $resolved = [];

                foreach ($pathList as $path) {
                    if (!is_string($path) || $path === '') {
                        $this->addInvalidAutoloadDiagnostic($diagnostics, 'Every Composer PSR-4 path must be a non-empty string.', $invalidCode);

                        return null;
                    }

                    $resolved[] = Path::resolveAbsolute($path, $base);
                }

                $psr4[$prefix] = $this->stableUnique($resolved);
            }
        }

        if (isset($autoload['psr-0'])) {
            if (
                !is_array($autoload['psr-0'])
                || ($autoload['psr-0'] !== [] && array_is_list($autoload['psr-0']))
            ) {
                $this->addInvalidAutoloadDiagnostic($diagnostics, 'Composer property "autoload.psr-0" must be an object.', $invalidCode);

                return null;
            }

            foreach ($autoload['psr-0'] as $prefix => $paths) {
                if (
                    !is_string($prefix)
                    || (!is_string($paths) && !is_array($paths))
                    || (is_array($paths) && !array_is_list($paths))
                ) {
                    $this->addInvalidAutoloadDiagnostic($diagnostics, 'Every Composer PSR-0 mapping must contain a string path or a list of string paths.', $invalidCode);

                    return null;
                }

                $pathList = is_string($paths) ? [$paths] : $paths;
                $resolved = [];

                foreach ($pathList as $path) {
                    if (!is_string($path) || $path === '') {
                        $this->addInvalidAutoloadDiagnostic($diagnostics, 'Every Composer PSR-0 path must be a non-empty string.', $invalidCode);

                        return null;
                    }

                    $resolved[] = Path::resolveAbsolute($path, $base);
                }

                $psr0[$prefix] = $this->stableUnique($resolved);
            }
        }

        if (isset($autoload['exclude-from-classmap'])) {
            $entries = $this->readStringList(
                $autoload['exclude-from-classmap'],
                'autoload.exclude-from-classmap',
                $diagnostics,
                $invalidCode,
            );

            if ($entries === null) {
                return null;
            }

            foreach ($entries as $entry) {
                if (str_contains($entry, '?') || str_contains($entry, '[') || str_contains($entry, ']')) {
                    $this->addInvalidAutoloadDiagnostic($diagnostics, 'Composer exclude-from-classmap patterns support path segments and * wildcards only.', $invalidCode);

                    return null;
                }

                $entry = str_replace('\\', '/', $entry);

                if (str_ends_with($entry, '/')) {
                    $entry .= '**';
                }

                $excludeFromClassmap[] = Path::resolveAbsolute(ltrim($entry, '/'), $base);
            }
        }

        if (isset($autoload['classmap'])) {
            $entries = $this->readStringList($autoload['classmap'], 'autoload.classmap', $diagnostics, $invalidCode);

            if ($entries === null) {
                return null;
            }

            foreach ($entries as $entry) {
                if (str_contains($entry, '?') || str_contains($entry, '[') || str_contains($entry, ']')) {
                    $this->addInvalidAutoloadDiagnostic($diagnostics, 'Composer classmap entries support paths and * wildcards only.', $invalidCode);

                    return null;
                }

                $path = Path::resolveAbsolute($entry, $base);

                if ($expandClassmapDirectories) {
                    array_push($classmap, ...$this->discoverClassmapFiles($path, $excludeFromClassmap));
                } elseif (is_file($path) && str_ends_with(strtolower($path), '.php')) {
                    $classmap[] = $path;
                } else {
                    $classmap[] = $path;
                }
            }
        }

        if (isset($autoload['files'])) {
            $entries = $this->readStringList($autoload['files'], 'autoload.files', $diagnostics, $invalidCode);

            if ($entries === null) {
                return null;
            }

            foreach ($entries as $entry) {
                $files[] = Path::resolveAbsolute($entry, $base);
            }
        }

        return new AutoloadMap(
            psr4: $psr4,
            classmap: $this->stableUnique($classmap),
            files: $this->stableUnique($files),
            psr0: $psr0,
            excludeFromClassmap: $this->stableUnique($excludeFromClassmap),
        );
    }

    /** @return list<ComposerPackage> */
    private function resolveInstalledPackages(string $vendorPath, DiagnosticBag $diagnostics): array
    {
        $installedPath = Path::join($vendorPath, 'composer/installed.json');

        if (!file_exists($installedPath)) {
            return [];
        }

        $installed = $this->readJson($installedPath, DiagnosticCode::InvalidInstalledComposerMetadata, $diagnostics);

        if ($installed === null || !is_array($installed)) {
            if ($installed !== null || !$diagnostics->hasErrors) {
                $this->addInvalidInstalledDiagnostic($diagnostics, 'Composer installed metadata must be an object or package list.');
            }

            return [];
        }

        $installedPackages = array_key_exists('packages', $installed) ? $installed['packages'] : $installed;

        if (!is_array($installedPackages) || !array_is_list($installedPackages)) {
            $this->addInvalidInstalledDiagnostic($diagnostics, 'Composer installed metadata property "packages" must be a list.');

            return [];
        }

        $packages = [];
        $installedDirectory = dirname($installedPath);
        $metadataIdentity = $this->fileIdentity($installedPath);
        $developmentPackageNames = [];

        if (array_key_exists('packages', $installed)) {
            $dev = $installed['dev'] ?? null;
            $devPackageNames = $installed['dev-package-names'] ?? [];

            if (($dev !== null && !is_bool($dev)) || !is_array($devPackageNames) || !array_is_list($devPackageNames)) {
                $this->addInvalidInstalledDiagnostic($diagnostics, 'Composer installed development metadata is invalid.');

                return [];
            }

            foreach ($devPackageNames as $name) {
                if (!is_string($name) || $name === '') {
                    $this->addInvalidInstalledDiagnostic($diagnostics, 'Composer installed development package names must be non-empty strings.');

                    return [];
                }

                $developmentPackageNames[strtolower($name)] = true;
            }
        }

        foreach ($installedPackages as $package) {
            if (
                !is_array($package)
                || !isset($package['name'])
                || !is_string($package['name'])
                || $package['name'] === ''
            ) {
                $this->addInvalidInstalledDiagnostic($diagnostics, 'Every installed Composer package must contain a string name.');
                continue;
            }

            $installPath = $package['install_path'] ?? $package['install-path'] ?? null;
            $packageRoot = is_string($installPath) && $installPath !== ''
                ? Path::resolveAbsolute($installPath, $installedDirectory)
                : Path::join($vendorPath, $package['name']);
            $autoload = $package['autoload'] ?? [];

            if (!is_array($autoload)) {
                $this->addInvalidInstalledDiagnostic($diagnostics, sprintf('Installed package "%s" has invalid autoload metadata.', $package['name']));
                continue;
            }

            if (
                (array_key_exists('install_path', $package) || array_key_exists('install-path', $package))
                && (!is_string($installPath) || $installPath === '')
            ) {
                $this->addInvalidInstalledDiagnostic($diagnostics, sprintf('Installed package "%s" has an invalid install path.', $package['name']));
                continue;
            }

            $map = $this->parseAutoload(
                $autoload,
                $packageRoot,
                $diagnostics,
                DiagnosticCode::InvalidInstalledComposerMetadata,
                false,
            );

            if ($map !== null) {
                $version = $package['version'] ?? null;
                $prettyVersion = $package['pretty_version'] ?? $package['pretty-version'] ?? null;
                $type = $package['type'] ?? null;
                $dist = $package['dist'] ?? null;
                $source = $package['source'] ?? null;
                $reference = (is_array($dist) ? ($dist['reference'] ?? null) : null)
                    ?? (is_array($source) ? ($source['reference'] ?? null) : null);

                $requirements = $this->readRequirements($package['require'] ?? [], $package['name'], $diagnostics);
                $developmentOnly = $package['dev_requirement'] ?? isset($developmentPackageNames[strtolower($package['name'])]);

                if (($version !== null && !is_string($version))
                    || ($prettyVersion !== null && !is_string($prettyVersion))
                    || ($type !== null && !is_string($type))
                    || ($reference !== null && !is_string($reference))
                    || !is_bool($developmentOnly)
                    || $requirements === null) {
                    $this->addInvalidInstalledDiagnostic($diagnostics, sprintf('Installed package "%s" has invalid identity metadata.', $package['name']));
                    continue;
                }

                $extensionRequirements = array_filter(
                    $requirements,
                    static fn (string $constraint, string $name): bool => str_starts_with(strtolower($name), 'ext-'),
                    ARRAY_FILTER_USE_BOTH,
                );

                $packages[] = new ComposerPackage(
                    name: $package['name'],
                    installPath: $packageRoot,
                    autoload: $map,
                    version: $version,
                    reference: $reference,
                    prettyVersion: $prettyVersion,
                    type: $type,
                    developmentOnly: $developmentOnly,
                    requirements: $requirements,
                    extensionRequirements: $extensionRequirements,
                    installedMetadataIdentity: $metadataIdentity,
                );
            }
        }

        return $packages;
    }

    /** @param list<AutoloadMap> $maps */
    private function merge(array $maps): AutoloadMap
    {
        /** @var array<string, list<string>> $psr4 */
        $psr4 = [];
        /** @var array<string, list<string>> $psr0 */
        $psr0 = [];
        /** @var list<string> $classmap */
        $classmap = [];
        /** @var list<string> $files */
        $files = [];
        /** @var list<string> $excludeFromClassmap */
        $excludeFromClassmap = [];

        foreach ($maps as $map) {
            foreach ($map->psr4 as $prefix => $paths) {
                $psr4[$prefix] = [...($psr4[$prefix] ?? []), ...$paths];
            }

            foreach ($map->psr0 as $prefix => $paths) {
                $psr0[$prefix] = [...($psr0[$prefix] ?? []), ...$paths];
            }

            array_push($classmap, ...$map->classmap);
            array_push($files, ...$map->files);
            array_push($excludeFromClassmap, ...$map->excludeFromClassmap);
        }

        foreach ($psr4 as $prefix => $paths) {
            $psr4[$prefix] = $this->stableUnique($paths);
        }

        foreach ($psr0 as $prefix => $paths) {
            $psr0[$prefix] = $this->stableUnique($paths);
        }

        return new AutoloadMap(
            psr4: $psr4,
            classmap: $this->stableUnique($classmap),
            files: $this->stableUnique($files),
            psr0: $psr0,
            excludeFromClassmap: $this->stableUnique($excludeFromClassmap),
        );
    }

    /** @return array<string, mixed>|null */
    private function readObject(
        string $path,
        DiagnosticCode $code,
        DiagnosticBag $diagnostics,
    ): ?array {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            $diagnostics->add(new Diagnostic($code, sprintf('The file "%s" could not be read.', basename($path))));

            return null;
        }

        try {
            $rootValue = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $diagnostics->add(new Diagnostic(
                $code,
                sprintf('The file "%s" does not contain valid JSON.', basename($path)),
                debug: ['message' => $exception->getMessage()],
            ));

            return null;
        }

        if (!is_object($rootValue) || !is_array($decoded)) {
            $diagnostics->add(new Diagnostic(
                $code,
                sprintf('The file "%s" must contain a JSON object.', basename($path)),
            ));

            return null;
        }

        $object = [];

        foreach ($decoded as $property => $value) {
            if (!is_string($property)) {
                $diagnostics->add(new Diagnostic($code, sprintf('The file "%s" contains a non-string object property.', basename($path))));

                return null;
            }

            $object[$property] = $value;
        }

        return $object;
    }

    private function readJson(
        string $path,
        DiagnosticCode $code,
        DiagnosticBag $diagnostics,
    ): mixed {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            $diagnostics->add(new Diagnostic($code, sprintf('The file "%s" could not be read.', basename($path))));

            return null;
        }

        try {
            return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $diagnostics->add(new Diagnostic($code, sprintf('The file "%s" does not contain valid JSON.', basename($path)), debug: ['message' => $exception->getMessage()]));

            return null;
        }
    }

    /** @return list<string>|null */
    private function readStringList(
        mixed $value,
        string $property,
        DiagnosticBag $diagnostics,
        DiagnosticCode $invalidCode,
    ): ?array {
        if (!is_array($value) || !array_is_list($value)) {
            $this->addInvalidAutoloadDiagnostic($diagnostics, sprintf('Composer property "%s" must be a list of strings.', $property), $invalidCode);

            return null;
        }

        $result = [];

        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '') {
                $this->addInvalidAutoloadDiagnostic($diagnostics, sprintf('Every entry in Composer property "%s" must be a non-empty string.', $property), $invalidCode);

                return null;
            }

            $result[] = $entry;
        }

        return $result;
    }

    /** @return array<string, string>|null */
    private function readRequirements(mixed $value, string $package, DiagnosticBag $diagnostics): ?array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->addInvalidInstalledDiagnostic($diagnostics, sprintf('Installed package "%s" has invalid requirements.', $package));

            return null;
        }

        $requirements = [];

        foreach ($value as $name => $constraint) {
            if (!is_string($name) || $name === '' || !is_string($constraint) || $constraint === '') {
                $this->addInvalidInstalledDiagnostic($diagnostics, sprintf('Installed package "%s" has invalid requirements.', $package));

                return null;
            }

            $requirements[$name] = $constraint;
        }

        return $requirements;
    }

    private function fileIdentity(string $path): ?string
    {
        return is_file($path) ? 'sha256:' . hash_file('sha256', $path) : null;
    }

    /**
     * @param list<string> $exclusions
     * @return list<string>
     */
    private function discoverClassmapFiles(string $entry, array $exclusions): array
    {
        if (!str_contains($entry, '*')) {
            if (is_file($entry) && str_ends_with(strtolower($entry), '.php')) {
                return $this->matchesAnyPattern($entry, $exclusions) ? [] : [$entry];
            }

            return is_dir($entry) && !is_link($entry)
                ? $this->discoverPhpFiles($entry, $exclusions)
                : [$entry];
        }

        $wildcard = strpos($entry, '*');

        if ($wildcard === false) {
            throw new \LogicException('The classmap entry was expected to contain a wildcard.');
        }

        $base = rtrim(substr($entry, 0, $wildcard), '/');

        while ($base !== '' && !is_dir($base)) {
            $parent = dirname($base);

            if ($parent === $base) {
                return [$entry];
            }

            $base = $parent;
        }

        if ($base === '' || !is_dir($base)) {
            return [$entry];
        }

        return array_values(array_filter(
            $this->discoverPhpFiles($base, $exclusions),
            fn (string $path): bool => $this->matchesComposerPattern($path, $entry, true),
        ));
    }

    /**
     * @param list<string> $exclusions
     * @return list<string>
     */
    private function discoverPhpFiles(string $directory, array $exclusions = []): array
    {
        $files = [];
        $entries = [];

        try {
            foreach (new \DirectoryIterator($directory) as $entry) {
                if (!$entry->isDot()) {
                    $entries[] = Path::normalize($entry->getPathname());
                }
            }
        } catch (\UnexpectedValueException) {
            return [];
        }

        sort($entries, SORT_STRING);

        foreach ($entries as $path) {
            if (is_dir($path) && !is_link($path)) {
                array_push($files, ...$this->discoverPhpFiles($path, $exclusions));
            } elseif (
                is_file($path)
                && str_ends_with(strtolower($path), '.php')
                && !$this->matchesAnyPattern($path, $exclusions)
            ) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /** @param list<string> $patterns */
    private function matchesAnyPattern(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesComposerPattern($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesComposerPattern(string $path, string $pattern, bool $includeDescendants = false): bool
    {
        $quoted = preg_quote(Path::normalize($pattern), '~');
        $quoted = str_replace('\\*\\*', '.*', $quoted);
        $quoted = str_replace('\\*', '[^/]*', $quoted);
        $suffix = $includeDescendants ? '(?:/.*)?' : '';

        return preg_match('~^' . $quoted . $suffix . '$~D', Path::normalize($path)) === 1;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function stableUnique(array $paths): array
    {
        $result = [];
        $seen = [];

        foreach ($paths as $path) {
            $normalized = Path::normalize($path);
            $key = Path::buildComparisonKey($normalized);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $normalized;
            }
        }

        return $result;
    }

    private function addInvalidAutoloadDiagnostic(
        DiagnosticBag $diagnostics,
        string $message,
        DiagnosticCode $code = DiagnosticCode::InvalidComposerAutoloadMapping,
    ): void {
        $diagnostics->add(new Diagnostic(
            $code,
            $message,
        ));
    }

    private function addInvalidInstalledDiagnostic(DiagnosticBag $diagnostics, string $message): void
    {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::InvalidInstalledComposerMetadata,
            $message,
        ));
    }

}
