<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer;

use Amasiye\Ppphp\Config\ProjectConfig;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Support\Path;

final class ComposerRuntimeConfigurator
{
    public function project(ProjectConfig $configuration): ComposerRuntimeProjection
    {
        $diagnostics = new DiagnosticBag();
        $configurationPath = Path::join($configuration->projectRoot, 'composer.json');

        if (!is_file($configurationPath) || is_link($configurationPath)) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::InvalidComposerConfiguration,
                Severity::Error,
                'Composer Configuration Is Not Available',
                is_link($configurationPath)
                    ? 'The root composer.json cannot be a symbolic link.'
                    : 'The project root does not contain a composer.json file.',
            ));

            return new ComposerRuntimeProjection($configurationPath, null, null, $diagnostics);
        }

        $contents = @file_get_contents($configurationPath);

        if ($contents === false) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::InvalidComposerConfiguration,
                Severity::Error,
                'Invalid Composer Configuration',
                'The root composer.json file could not be read.',
            ));

            return new ComposerRuntimeProjection($configurationPath, null, null, $diagnostics);
        }

        try {
            $document = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::InvalidComposerConfiguration,
                Severity::Error,
                'Invalid Composer Configuration',
                'The root composer.json does not contain valid JSON.',
                debug: ['message' => $exception->getMessage()],
            ));

            return new ComposerRuntimeProjection($configurationPath, $contents, null, $diagnostics);
        }

        if (!$document instanceof \stdClass) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::InvalidComposerConfiguration,
                Severity::Error,
                'Invalid Composer Configuration',
                'The root composer.json must contain a JSON object.',
            ));

            return new ComposerRuntimeProjection($configurationPath, $contents, null, $diagnostics);
        }

        $extra = $this->readOrCreateObject($document, 'extra', 'Composer property "extra"', $diagnostics);
        $ppphp = $extra === null
            ? null
            : $this->readOrCreateObject($extra, 'ppphp', 'Composer property "extra.ppphp"', $diagnostics);
        $unprojectedMappings = [];

        if ($ppphp !== null) {
            foreach ([
                ['autoload', 'source-autoload'],
                ['autoload-dev', 'source-autoload-dev'],
            ] as [$runtimeName, $sourceName]) {
                $runtime = $this->readRuntimeSection($document, $runtimeName, $diagnostics);
                $captured = $this->captureSourceMappings($runtime, $configuration, $runtimeName, $diagnostics);
                $source = property_exists($ppphp, $sourceName)
                    ? $this->readSourceSection($ppphp->{$sourceName}, $sourceName, $diagnostics)
                    : $captured;

                if ($source === null) {
                    continue;
                }

                $this->applySourceMappings(
                    $runtime,
                    $source,
                    $configuration,
                    $runtimeName,
                    $diagnostics,
                    $unprojectedMappings,
                );
                $ppphp->{$sourceName} = $source;
            }
        }

        if ($diagnostics->hasErrors) {
            return new ComposerRuntimeProjection(
                $configurationPath,
                $contents,
                null,
                $diagnostics,
                $unprojectedMappings,
            );
        }

        $projected = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";

        return new ComposerRuntimeProjection(
            $configurationPath,
            $contents,
            $projected,
            $diagnostics,
            $unprojectedMappings,
        );
    }

    private function readOrCreateObject(
        \stdClass $owner,
        string $property,
        string $label,
        DiagnosticBag $diagnostics,
    ): ?\stdClass {
        if (!property_exists($owner, $property)) {
            $owner->{$property} = new \stdClass();

            return $owner->{$property};
        }

        if (!$owner->{$property} instanceof \stdClass) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::ComposerRuntimeMappingConflictsWithBuildOutput,
                Severity::Error,
                'Composer Runtime Mapping Conflicts With Build Output',
                sprintf('%s must be a JSON object before ++PHP metadata can be stored.', $label),
            ));

            return null;
        }

        return $owner->{$property};
    }

    private function readRuntimeSection(
        \stdClass $document,
        string $name,
        DiagnosticBag $diagnostics,
    ): ?\stdClass {
        if (!property_exists($document, $name)) {
            return null;
        }

        if (!$document->{$name} instanceof \stdClass) {
            $this->addMappingError(
                $diagnostics,
                sprintf('Composer property "%s" must be a JSON object.', $name),
            );

            return null;
        }

        return $document->{$name};
    }

    private function readSourceSection(
        mixed $value,
        string $name,
        DiagnosticBag $diagnostics,
    ): ?\stdClass {
        if (!$value instanceof \stdClass) {
            $this->addMappingError(
                $diagnostics,
                sprintf('Composer property "extra.ppphp.%s" must be a JSON object.', $name),
            );

            return null;
        }

        $psr4 = $value->{'psr-4'} ?? new \stdClass();
        $classmap = $value->classmap ?? [];
        $files = $value->files ?? [];

        if (!$psr4 instanceof \stdClass) {
            $this->addMappingError($diagnostics, sprintf('Composer property "extra.ppphp.%s.psr-4" must be a JSON object.', $name));

            return null;
        }

        if ($this->readStringList($classmap, sprintf('extra.ppphp.%s.classmap', $name), $diagnostics) === null
            || $this->readStringList($files, sprintf('extra.ppphp.%s.files', $name), $diagnostics) === null) {
            return null;
        }

        foreach (get_object_vars($psr4) as $prefix => $paths) {
            if ($this->readPathValue($paths, sprintf('extra.ppphp.%s.psr-4.%s', $name, $prefix), $diagnostics) === null) {
                return null;
            }
        }

        $value->{'psr-4'} = $psr4;
        $value->classmap = $classmap;
        $value->files = $files;

        return $value;
    }

    private function captureSourceMappings(
        ?\stdClass $runtime,
        ProjectConfig $configuration,
        string $section,
        DiagnosticBag $diagnostics,
    ): \stdClass {
        $source = new \stdClass();
        $source->{'psr-4'} = new \stdClass();
        $source->classmap = [];
        $source->files = [];

        if ($runtime === null) {
            return $source;
        }

        $psr4 = $runtime->{'psr-4'} ?? new \stdClass();

        if (!$psr4 instanceof \stdClass) {
            $this->addMappingError($diagnostics, sprintf('Composer property "%s.psr-4" must be a JSON object.', $section));

            return $source;
        }

        foreach (get_object_vars($psr4) as $prefix => $value) {
            $paths = $this->readPathValue($value, sprintf('%s.psr-4.%s', $section, $prefix), $diagnostics);

            if ($paths === null) {
                continue;
            }

            $owned = array_values(array_filter(
                $paths,
                fn (string $path): bool => $this->projectPath($path, $configuration, false) !== null,
            ));

            if ($owned !== []) {
                $source->{'psr-4'}->{$prefix} = is_string($value) ? $owned[0] : $owned;
            }
        }

        foreach (['classmap', 'files'] as $kind) {
            $paths = $this->readStringList(
                $runtime->{$kind} ?? [],
                sprintf('%s.%s', $section, $kind),
                $diagnostics,
            );

            if ($paths === null) {
                continue;
            }

            $source->{$kind} = array_values(array_filter(
                $paths,
                fn (string $path): bool => $this->projectPath($path, $configuration, $kind === 'files') !== null,
            ));
        }

        return $source;
    }

    /** @param list<ComposerRuntimeMapping> $unprojectedMappings */
    private function applySourceMappings(
        ?\stdClass $runtime,
        \stdClass $source,
        ProjectConfig $configuration,
        string $section,
        DiagnosticBag $diagnostics,
        array &$unprojectedMappings,
    ): void {
        $sourcePsr4 = $source->{'psr-4'};
        $sourceClassmap = $this->readStringList($source->classmap, sprintf('extra.ppphp.source-%s.classmap', $section), $diagnostics);
        $sourceFiles = $this->readStringList($source->files, sprintf('extra.ppphp.source-%s.files', $section), $diagnostics);

        if (!$sourcePsr4 instanceof \stdClass || $sourceClassmap === null || $sourceFiles === null) {
            $this->addMappingError($diagnostics, sprintf('Preserved Composer mappings for "%s" are invalid.', $section));

            return;
        }

        $hasEntries = get_object_vars($sourcePsr4) !== [] || $sourceClassmap !== [] || $sourceFiles !== [];

        if ($runtime === null) {
            if ($hasEntries) {
                $this->addConflict($diagnostics, sprintf('Composer property "%s" no longer exists, but preserved ++PHP source mappings still refer to it.', $section));
            }

            return;
        }

        $runtimePsr4 = $runtime->{'psr-4'} ?? new \stdClass();

        foreach (get_object_vars($sourcePsr4) as $prefix => $sourceValue) {
            if (!$runtimePsr4 instanceof \stdClass || !property_exists($runtimePsr4, $prefix)) {
                $this->addConflict($diagnostics, sprintf('Composer mapping "%s.psr-4.%s" no longer exists.', $section, $prefix));
                continue;
            }

            $runtimePsr4->{$prefix} = $this->replacePaths(
                $runtimePsr4->{$prefix},
                $this->readPathValue($sourceValue, sprintf('extra.ppphp.source-%s.psr-4.%s', $section, $prefix), $diagnostics) ?? [],
                $configuration,
                false,
                $section,
                'psr-4.' . $prefix,
                $diagnostics,
                $unprojectedMappings,
            );
        }

        if ($runtimePsr4 instanceof \stdClass) {
            $runtime->{'psr-4'} = $runtimePsr4;
        }

        foreach (['classmap' => $sourceClassmap, 'files' => $sourceFiles] as $kind => $sourcePaths) {
            if ($sourcePaths === []) {
                continue;
            }

            if (!property_exists($runtime, $kind)) {
                $this->addConflict($diagnostics, sprintf('Composer mapping "%s.%s" no longer exists.', $section, $kind));
                continue;
            }

            $runtime->{$kind} = $this->replacePaths(
                $runtime->{$kind},
                $sourcePaths,
                $configuration,
                $kind === 'files',
                $section,
                $kind,
                $diagnostics,
                $unprojectedMappings,
            );
        }
    }

    /**
     * @param list<string> $sourcePaths
     * @param list<ComposerRuntimeMapping> $unprojectedMappings
     */
    private function replacePaths(
        mixed $runtimeValue,
        array $sourcePaths,
        ProjectConfig $configuration,
        bool $isFile,
        string $section,
        string $entry,
        DiagnosticBag $diagnostics,
        array &$unprojectedMappings,
    ): mixed {
        $runtimePaths = $this->readPathValue($runtimeValue, sprintf('%s.%s', $section, $entry), $diagnostics);

        if ($runtimePaths === null) {
            return $runtimeValue;
        }

        foreach ($sourcePaths as $sourcePath) {
            $expectedPath = $this->projectPath($sourcePath, $configuration, $isFile);

            if ($expectedPath === null) {
                $diagnostics->add(new Diagnostic(
                    DiagnosticCode::ComposerAutoloadMappingCannotBeProjected,
                    Severity::Error,
                    'Composer Autoload Mapping Cannot Be Projected',
                    sprintf('The preserved source path "%s" is no longer beneath a configured source root.', $sourcePath),
                ));
                continue;
            }

            $sourceIndex = $this->findEquivalentPath(array_values($runtimePaths), $sourcePath, $configuration->projectRoot);
            $expectedIndex = $this->findEquivalentPath(array_values($runtimePaths), $expectedPath, $configuration->projectRoot);

            if ($sourceIndex !== null) {
                $runtimePaths[$sourceIndex] = $expectedPath;
                $unprojectedMappings[] = new ComposerRuntimeMapping($section, $entry, $sourcePath, $expectedPath);
            } elseif ($expectedIndex === null) {
                $this->addConflict(
                    $diagnostics,
                    sprintf(
                        'Composer mapping "%s.%s" contains neither preserved source path "%s" nor expected build path "%s".',
                        $section,
                        $entry,
                        $sourcePath,
                        $expectedPath,
                    ),
                );
            }
        }

        return is_string($runtimeValue) ? $runtimePaths[0] : $runtimePaths;
    }

    private function projectPath(string $path, ProjectConfig $configuration, bool $isFile): ?string
    {
        $absolute = Path::resolveAbsolute($path, $configuration->projectRoot);
        $sourceRoots = $configuration->sourceRoots;
        usort($sourceRoots, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        $owner = null;

        foreach ($sourceRoots as $sourceRoot) {
            if (Path::contains($sourceRoot, $absolute)) {
                $owner = $sourceRoot;
                break;
            }
        }

        if ($owner === null) {
            return null;
        }

        $relative = Path::resolveRelativeTo($absolute, $owner);
        $projected = $relative === '.'
            ? $configuration->outputPath
            : Path::join($configuration->outputPath, $relative);

        if ($isFile && str_ends_with(strtolower($projected), '.ppphp')) {
            $projected = substr($projected, 0, -6) . '.php';
        }

        $result = Path::resolveRelativeTo($projected, $configuration->projectRoot);

        if (!$isFile && str_ends_with(str_replace('\\', '/', $path), '/') && $result !== '.') {
            $result .= '/';
        }

        return $result;
    }

    /** @param list<string> $paths */
    private function findEquivalentPath(array $paths, string $candidate, string $projectRoot): ?int
    {
        $candidateKey = Path::buildComparisonKey(Path::resolveAbsolute($candidate, $projectRoot));

        foreach ($paths as $index => $path) {
            $pathKey = Path::buildComparisonKey(Path::resolveAbsolute($path, $projectRoot));

            if ($pathKey === $candidateKey) {
                return $index;
            }
        }

        return null;
    }

    /** @return list<string>|null */
    private function readPathValue(mixed $value, string $property, DiagnosticBag $diagnostics): ?array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return $this->readStringList($value, $property, $diagnostics);
    }

    /** @return list<string>|null */
    private function readStringList(mixed $value, string $property, DiagnosticBag $diagnostics): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->addMappingError($diagnostics, sprintf('Composer property "%s" must be a list of paths.', $property));

            return null;
        }

        foreach ($value as $path) {
            if (!is_string($path) || $path === '') {
                $this->addMappingError($diagnostics, sprintf('Every path in Composer property "%s" must be a non-empty string.', $property));

                return null;
            }
        }

        return $value;
    }

    private function addMappingError(DiagnosticBag $diagnostics, string $message): void
    {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::InvalidComposerAutoloadMapping,
            Severity::Error,
            'Invalid Composer Autoload Mapping',
            $message,
        ));
    }

    private function addConflict(DiagnosticBag $diagnostics, string $message): void
    {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::ComposerRuntimeMappingConflictsWithBuildOutput,
            Severity::Error,
            'Composer Runtime Mapping Conflicts With Build Output',
            $message,
        ));
    }
}
