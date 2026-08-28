<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Interop\Composer;

use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Support\Path;

final class ComposerResolver
{
    public function resolve(string $projectRoot): ComposerResolutionResult
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
            'Invalid Composer Configuration',
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
                $this->invalidAutoload($diagnostics, sprintf('Composer property "%s" must be an object.', $section));
                continue;
            }

            $map = $this->parseAutoload($configuration[$section], $projectRoot, $diagnostics);

            if ($map !== null) {
                $projectMaps[] = $map;
            }
        }

        $vendorPath = Path::join($projectRoot, 'vendor');

        if (isset($configuration['config'])) {
            if (!is_array($configuration['config'])) {
                $this->invalidAutoload($diagnostics, 'Composer property "config" must be an object.');
            } elseif (array_key_exists('vendor-dir', $configuration['config'])) {
                $vendorDirectory = $configuration['config']['vendor-dir'];

                if (!is_string($vendorDirectory) || $vendorDirectory === '') {
                    $this->invalidAutoload($diagnostics, 'Composer property "config.vendor-dir" must be a non-empty string.');
                } else {
                    $vendorPath = Path::absolute($vendorDirectory, $projectRoot);
                }
            }
        }

        $dependencyMaps = $this->installedPackageMaps($vendorPath, $diagnostics);

        if ($diagnostics->hasErrors()) {
            return new ComposerResolutionResult(null, $diagnostics);
        }

        return new ComposerResolutionResult(new ComposerProject(
            $projectRoot,
            $configurationPath,
            $vendorPath,
            $this->merge($projectMaps),
            $this->merge($dependencyMaps),
        ), $diagnostics);
    }

    /**
     * @param array<mixed, mixed> $autoload
     */
    private function parseAutoload(
        array $autoload,
        string $base,
        DiagnosticBag $diagnostics,
        DiagnosticCode $invalidCode = DiagnosticCode::InvalidComposerAutoloadMapping,
    ): ?AutoloadMap {
        /** @var array<string, list<string>> $psr4 */
        $psr4 = [];
        /** @var list<string> $classmap */
        $classmap = [];
        /** @var list<string> $files */
        $files = [];

        if (isset($autoload['psr-4'])) {
            if (
                !is_array($autoload['psr-4'])
                || ($autoload['psr-4'] !== [] && array_is_list($autoload['psr-4']))
            ) {
                $this->invalidAutoload($diagnostics, 'Composer property "autoload.psr-4" must be an object.', $invalidCode);

                return null;
            }

            foreach ($autoload['psr-4'] as $prefix => $paths) {
                if (
                    !is_string($prefix)
                    || (!is_string($paths) && !is_array($paths))
                    || (is_array($paths) && !array_is_list($paths))
                ) {
                    $this->invalidAutoload($diagnostics, 'Every Composer PSR-4 mapping must contain a string path or a list of string paths.', $invalidCode);

                    return null;
                }

                $pathList = is_string($paths) ? [$paths] : $paths;
                $resolved = [];

                foreach ($pathList as $path) {
                    if (!is_string($path) || $path === '') {
                        $this->invalidAutoload($diagnostics, 'Every Composer PSR-4 path must be a non-empty string.', $invalidCode);

                        return null;
                    }

                    $resolved[] = Path::absolute($path, $base);
                }

                $psr4[$prefix] = $this->sortedUnique($resolved);
            }

            ksort($psr4, SORT_STRING);
        }

        if (isset($autoload['classmap'])) {
            $entries = $this->stringList($autoload['classmap'], 'autoload.classmap', $diagnostics, $invalidCode);

            if ($entries === null) {
                return null;
            }

            foreach ($entries as $entry) {
                $path = Path::absolute($entry, $base);

                if (is_dir($path) && !is_link($path)) {
                    array_push($classmap, ...$this->phpFiles($path));
                } elseif (is_file($path) && str_ends_with(strtolower($path), '.php')) {
                    $classmap[] = $path;
                } else {
                    $classmap[] = $path;
                }
            }
        }

        if (isset($autoload['files'])) {
            $entries = $this->stringList($autoload['files'], 'autoload.files', $diagnostics, $invalidCode);

            if ($entries === null) {
                return null;
            }

            foreach ($entries as $entry) {
                $files[] = Path::absolute($entry, $base);
            }
        }

        return new AutoloadMap($psr4, $this->sortedUnique($classmap), $this->sortedUnique($files));
    }

    /** @return list<AutoloadMap> */
    private function installedPackageMaps(string $vendorPath, DiagnosticBag $diagnostics): array
    {
        $installedPath = Path::join($vendorPath, 'composer/installed.json');

        if (!file_exists($installedPath)) {
            return [];
        }

        $installed = $this->readJson($installedPath, DiagnosticCode::InvalidInstalledComposerMetadata, 'Invalid Installed Composer Metadata', $diagnostics);

        if ($installed === null || !is_array($installed)) {
            if ($installed !== null || !$diagnostics->hasErrors()) {
                $this->invalidInstalled($diagnostics, 'Composer installed metadata must be an object or package list.');
            }

            return [];
        }

        $packages = array_key_exists('packages', $installed) ? $installed['packages'] : $installed;

        if (!is_array($packages) || !array_is_list($packages)) {
            $this->invalidInstalled($diagnostics, 'Composer installed metadata property "packages" must be a list.');

            return [];
        }

        $maps = [];
        $installedDirectory = dirname($installedPath);

        foreach ($packages as $package) {
            if (
                !is_array($package)
                || !isset($package['name'])
                || !is_string($package['name'])
                || $package['name'] === ''
            ) {
                $this->invalidInstalled($diagnostics, 'Every installed Composer package must contain a string name.');
                continue;
            }

            $installPath = $package['install_path'] ?? $package['install-path'] ?? null;
            $packageRoot = is_string($installPath) && $installPath !== ''
                ? Path::absolute($installPath, $installedDirectory)
                : Path::join($vendorPath, $package['name']);
            $autoload = $package['autoload'] ?? [];

            if (!is_array($autoload)) {
                $this->invalidInstalled($diagnostics, sprintf('Installed package "%s" has invalid autoload metadata.', $package['name']));
                continue;
            }

            if (
                (array_key_exists('install_path', $package) || array_key_exists('install-path', $package))
                && (!is_string($installPath) || $installPath === '')
            ) {
                $this->invalidInstalled($diagnostics, sprintf('Installed package "%s" has an invalid install path.', $package['name']));
                continue;
            }

            $map = $this->parseAutoload(
                $autoload,
                $packageRoot,
                $diagnostics,
                DiagnosticCode::InvalidInstalledComposerMetadata,
            );

            if ($map !== null) {
                $maps[] = $map;
            }
        }

        return $maps;
    }

    /** @param list<AutoloadMap> $maps */
    private function merge(array $maps): AutoloadMap
    {
        /** @var array<string, list<string>> $psr4 */
        $psr4 = [];
        /** @var list<string> $classmap */
        $classmap = [];
        /** @var list<string> $files */
        $files = [];

        foreach ($maps as $map) {
            foreach ($map->psr4 as $prefix => $paths) {
                $psr4[$prefix] = [...($psr4[$prefix] ?? []), ...$paths];
            }

            array_push($classmap, ...$map->classmap);
            array_push($files, ...$map->files);
        }

        foreach ($psr4 as $prefix => $paths) {
            $psr4[$prefix] = $this->sortedUnique($paths);
        }

        ksort($psr4, SORT_STRING);

        return new AutoloadMap($psr4, $this->sortedUnique($classmap), $this->sortedUnique($files));
    }

    /** @return array<string, mixed>|null */
    private function readObject(
        string $path,
        DiagnosticCode $code,
        string $title,
        DiagnosticBag $diagnostics,
    ): ?array {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            $diagnostics->add(new Diagnostic($code, Severity::Error, $title, sprintf('The file "%s" could not be read.', $path)));

            return null;
        }

        try {
            $rootValue = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $diagnostics->add(new Diagnostic(
                $code,
                Severity::Error,
                $title,
                sprintf('The file "%s" does not contain valid JSON.', $path),
                debug: ['message' => $exception->getMessage()],
            ));

            return null;
        }

        if (!is_object($rootValue) || !is_array($decoded)) {
            $diagnostics->add(new Diagnostic(
                $code,
                Severity::Error,
                $title,
                sprintf('The file "%s" must contain a JSON object.', basename($path)),
            ));

            return null;
        }

        $object = [];

        foreach ($decoded as $property => $value) {
            if (!is_string($property)) {
                $diagnostics->add(new Diagnostic($code, Severity::Error, $title, sprintf('The file "%s" contains a non-string object property.', basename($path))));

                return null;
            }

            $object[$property] = $value;
        }

        return $object;
    }

    private function readJson(
        string $path,
        DiagnosticCode $code,
        string $title,
        DiagnosticBag $diagnostics,
    ): mixed {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            $diagnostics->add(new Diagnostic($code, Severity::Error, $title, sprintf('The file "%s" could not be read.', $path)));

            return null;
        }

        try {
            return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $diagnostics->add(new Diagnostic($code, Severity::Error, $title, sprintf('The file "%s" does not contain valid JSON.', $path), debug: ['message' => $exception->getMessage()]));

            return null;
        }
    }

    /** @return list<string>|null */
    private function stringList(
        mixed $value,
        string $property,
        DiagnosticBag $diagnostics,
        DiagnosticCode $invalidCode,
    ): ?array {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalidAutoload($diagnostics, sprintf('Composer property "%s" must be a list of strings.', $property), $invalidCode);

            return null;
        }

        $result = [];

        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '') {
                $this->invalidAutoload($diagnostics, sprintf('Every entry in Composer property "%s" must be a non-empty string.', $property), $invalidCode);

                return null;
            }

            $result[] = $entry;
        }

        return $result;
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
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
                array_push($files, ...$this->phpFiles($path));
            } elseif (is_file($path) && str_ends_with(strtolower($path), '.php')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function sortedUnique(array $paths): array
    {
        $paths = array_values(array_unique(array_map(Path::normalize(...), $paths)));
        usort($paths, static fn (string $left, string $right): int => Path::comparisonKey($left) <=> Path::comparisonKey($right));

        return $paths;
    }

    private function invalidAutoload(
        DiagnosticBag $diagnostics,
        string $message,
        DiagnosticCode $code = DiagnosticCode::InvalidComposerAutoloadMapping,
    ): void {
        $diagnostics->add(new Diagnostic(
            $code,
            Severity::Error,
            $code === DiagnosticCode::InvalidInstalledComposerMetadata
                ? 'Invalid Installed Composer Metadata'
                : 'Invalid Composer Autoload Mapping',
            $message,
        ));
    }

    private function invalidInstalled(DiagnosticBag $diagnostics, string $message): void
    {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::InvalidInstalledComposerMetadata,
            Severity::Error,
            'Invalid Installed Composer Metadata',
            $message,
        ));
    }

}
