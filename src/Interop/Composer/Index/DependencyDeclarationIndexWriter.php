<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer\Index;

use Amasiye\Ppphp\Analysis\Declaration\DeclarationReferenceCollector;
use Amasiye\Ppphp\Analysis\DeclarationContextEmitter;
use Amasiye\Ppphp\Compiler\Compiler;
use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Interop\Composer\AutoloadMap;
use Amasiye\Ppphp\Interop\Composer\ComposerPackage;
use Amasiye\Ppphp\Interop\Composer\ComposerProject;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Support\CanonicalJson;
use Amasiye\Ppphp\Support\Path;

final readonly class DependencyDeclarationIndexWriter
{
    public const int FORMAT_VERSION = 1;
    public const int DECLARATION_FORMAT_VERSION = 1;

    public function __construct(
        private DeclarationContextEmitter $emitter = new DeclarationContextEmitter(),
        private DeclarationReferenceCollector $references = new DeclarationReferenceCollector(),
    ) {}

    /** @return array<string, mixed> */
    public function write(
        ComposerProject $composer,
        ProjectParseResult $declarations,
        string $targetPhpVersion,
        string $outputDirectory,
    ): array {
        if (!$declarations->isSuccessful) {
            throw new \InvalidArgumentException('An invalid dependency declaration context cannot be serialized.');
        }

        if (count($declarations->classAliases) !== count($declarations->classAliasProvenance)) {
            throw new \InvalidArgumentException('Every dependency class alias must retain declaration provenance.');
        }

        $this->prepareOutput($outputDirectory);
        $exclusions = $this->declarationExclusions($declarations->parsedFiles);
        $filesByPackage = [];

        foreach ($declarations->parsedFiles as $file) {
            $provenance = $file->sourceFile->dependencyProvenance;

            if ($provenance !== null) {
                $filesByPackage[strtolower($provenance->packageName)][] = $file;
            }
        }

        $packageEntries = [];
        $totals = [
            'aliases' => count($declarations->classAliases),
            'classLikes' => 0,
            'conditionalDeclarations' => 0,
            'constants' => 0,
            'documents' => 0,
            'functions' => 0,
            'staticIncludes' => 0,
        ];

        foreach ($composer->dependencies as $packageOrder => $package) {
            $files = $filesByPackage[strtolower($package->name)] ?? [];
            $documents = [];
            $counts = ['classLikes' => 0, 'constants' => 0, 'functions' => 0];
            $forms = [];

            foreach ($files as $file) {
                $provenance = $file->sourceFile->dependencyProvenance;

                if ($provenance === null) {
                    continue;
                }

                $excluded = $exclusions[spl_object_id($file)] ?? [];
                $symbols = $this->filteredSymbols(
                    $this->references->collectDeclarations([$file]),
                    $excluded,
                );
                $documentCounts = [
                    'classLikes' => count($symbols['classes']),
                    'constants' => count($symbols['constants']),
                    'functions' => count($symbols['functions']),
                ];

                if (array_sum($documentCounts) === 0) {
                    continue;
                }

                foreach ($documentCounts as $name => $count) {
                    $counts[$name] += $count;
                    $totals[$name] += $count;
                }

                $forms[$provenance->autoloadForm] = true;
                $totals['conditionalDeclarations'] += $provenance->conditional
                    ? array_sum($documentCounts)
                    : 0;
                $totals['staticIncludes'] += $provenance->autoloadForm === 'include' ? 1 : 0;
                $totals['documents']++;
                $documents[] = [
                    'autoloadForm' => $provenance->autoloadForm,
                    'conditional' => $provenance->conditional,
                    'counts' => $documentCounts,
                    'order' => $provenance->declarationOrder,
                    'path' => $this->relativePath($provenance->packageRelativePath),
                    'source' => $this->emitter->emitPortable($file, $excluded),
                ];
            }

            $aliases = $this->packageAliases(
                $package,
                $declarations->classAliases,
                $declarations->classAliasProvenance,
            );
            $stableId = substr(hash('sha256', implode("\0", [
                $package->name,
                $package->version ?? '',
                $package->reference ?? '',
            ])), 0, 24);
            $shardPath = 'packages/' . $stableId . '.json';
            $shard = [
                'aliases' => $aliases,
                'autoload' => $this->portableAutoload($package),
                'counts' => [
                    ...$counts,
                    'aliases' => count($aliases),
                    'conditionalDeclarations' => array_sum(array_map(
                        static fn (array $document): int => $document['conditional'] ? array_sum($document['counts']) : 0,
                        $documents,
                    )),
                    'documents' => count($documents),
                    'staticIncludes' => count(array_filter(
                        $documents,
                        static fn (array $document): bool => $document['autoloadForm'] === 'include',
                    )),
                ],
                'declarationFormatVersion' => self::DECLARATION_FORMAT_VERSION,
                'documents' => $documents,
                'formatVersion' => self::FORMAT_VERSION,
                'package' => $this->packageIdentity($package, $packageOrder),
                'targetPhpVersion' => $targetPhpVersion,
            ];
            $json = CanonicalJson::encode($shard);
            $this->writeFile($outputDirectory, $shardPath, $json);
            $packageEntries[] = [
                ...$this->packageIdentity($package, $packageOrder),
                'autoloadForms' => array_keys($forms),
                'counts' => $shard['counts'],
                'path' => $shardPath,
                'sha256' => hash('sha256', $json),
            ];
        }

        $identityPayload = CanonicalJson::encode([
            'compilerVersion' => Compiler::VERSION,
            'declarationFormatVersion' => self::DECLARATION_FORMAT_VERSION,
            'packages' => $packageEntries,
            'targetPhpVersion' => $targetPhpVersion,
        ]);
        $manifest = [
            'compiler' => [
                'identity' => 'atatusoft-ltd/ppphp-src',
                'version' => Compiler::VERSION,
            ],
            'composerLockSha256' => $composer->composerLockIdentity,
            'contentIdentity' => 'sha256:' . hash('sha256', $identityPayload),
            'counts' => $totals,
            'declarationFormatVersion' => self::DECLARATION_FORMAT_VERSION,
            'formatVersion' => self::FORMAT_VERSION,
            'installedMetadataSha256' => $composer->installedMetadataIdentity,
            'packages' => $packageEntries,
            'targetPhpVersion' => $targetPhpVersion,
        ];
        $this->writeFile($outputDirectory, 'manifest.json', CanonicalJson::encode($manifest));

        return $manifest;
    }

    /**
     * @param array<string, ParsedFile> $files
     * @return array<int, array<string, true>>
     */
    private function declarationExclusions(array $files): array
    {
        /** @var array<string, array{fileId: int, conditional: bool, form: string}> $owners */
        $owners = [];
        $excluded = [];

        foreach ($files as $file) {
            $fileId = spl_object_id($file);
            $provenance = $file->sourceFile->dependencyProvenance;

            if ($provenance === null) {
                continue;
            }

            foreach ($this->references->collectDeclarations([$file]) as $kind => $names) {
                foreach ($names as $name) {
                    $key = $kind . ':' . strtolower(ltrim($name, '\\'));
                    $owner = $owners[$key] ?? null;

                    if ($owner === null) {
                        $owners[$key] = [
                            'fileId' => $fileId,
                            'conditional' => $provenance->conditional,
                            'form' => $provenance->autoloadForm,
                        ];
                        continue;
                    }

                    if ($owner['conditional'] && !$provenance->conditional) {
                        $excluded[$owner['fileId']][$key] = true;
                        $owners[$key] = [
                            'fileId' => $fileId,
                            'conditional' => false,
                            'form' => $provenance->autoloadForm,
                        ];
                        continue;
                    }

                    if (!$owner['conditional'] && $provenance->conditional) {
                        $excluded[$fileId][$key] = true;
                        continue;
                    }

                    $deterministicConditional = $provenance->conditional
                        && in_array($owner['form'], ['files', 'include'], true)
                        && in_array($provenance->autoloadForm, ['files', 'include'], true);
                    $deterministicClass = $kind === 'classes'
                        && !in_array($owner['form'], ['files', 'include'], true)
                        && !in_array($provenance->autoloadForm, ['files', 'include'], true);

                    if (!$deterministicConditional && !$deterministicClass) {
                        throw new \InvalidArgumentException(sprintf(
                            'Dependency declaration "%s" has no serializable runtime authority.',
                            $name,
                        ));
                    }

                    $excluded[$fileId][$key] = true;
                }
            }
        }

        return $excluded;
    }

    /**
     * @param array{classes: list<string>, functions: list<string>, constants: list<string>} $symbols
     * @param array<string, true> $excluded
     * @return array{classes: list<string>, functions: list<string>, constants: list<string>}
     */
    private function filteredSymbols(array $symbols, array $excluded): array
    {
        foreach ($symbols as $kind => $names) {
            $symbols[$kind] = array_values(array_filter(
                $names,
                static fn (string $name): bool => !isset($excluded[$kind . ':' . strtolower(ltrim($name, '\\'))]),
            ));
        }

        return $symbols;
    }

    /** @return array<string, mixed> */
    private function packageIdentity(ComposerPackage $package, int $order): array
    {
        return [
            'developmentOnly' => $package->developmentOnly,
            'name' => $package->name,
            'order' => $order,
            'prettyVersion' => $package->prettyVersion,
            'reference' => $package->reference,
            'type' => $package->type,
            'version' => $package->version,
        ];
    }

    /** @return array<string, mixed> */
    private function portableAutoload(ComposerPackage $package): array
    {
        return [
            'classmap' => $this->relativePaths($package->autoload->classmap, $package),
            'excludeFromClassmap' => $this->relativePaths($package->autoload->excludeFromClassmap, $package),
            'files' => $this->relativePaths($package->autoload->files, $package),
            'psr0' => $this->relativeMappings($package->autoload->psr0, $package),
            'psr4' => $this->relativeMappings($package->autoload->psr4, $package),
        ];
    }

    /**
     * @param array<string, list<string>> $mappings
     * @return array<string, list<string>>
     */
    private function relativeMappings(array $mappings, ComposerPackage $package): array
    {
        $portable = [];

        foreach ($mappings as $prefix => $paths) {
            $portable[$prefix] = $this->relativePaths($paths, $package);
        }

        return $portable;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function relativePaths(array $paths, ComposerPackage $package): array
    {
        return array_map(function (string $path) use ($package): string {
            $relative = Path::makeRelative($path, $package->installPath);

            if ($relative === null || str_starts_with($relative, '../')) {
                throw new \RuntimeException(sprintf('Dependency path for package "%s" is not portable.', $package->name));
            }

            return $this->relativePath($relative);
        }, $paths);
    }

    /**
     * @param array<string, string> $aliases
     * @param array<string, \Amasiye\Ppphp\Interop\Composer\DependencyDeclarationProvenance> $provenance
     * @return array<string, array{autoloadForm: string, order: int, original: string, path: string}>
     */
    private function packageAliases(ComposerPackage $package, array $aliases, array $provenance): array
    {
        $result = [];

        foreach ($aliases as $alias => $original) {
            $origin = $provenance[$alias] ?? null;

            if ($origin !== null && strcasecmp($origin->packageName, $package->name) === 0) {
                $result[$alias] = [
                    'autoloadForm' => $origin->autoloadForm,
                    'order' => $origin->declarationOrder,
                    'original' => $original,
                    'path' => $this->relativePath($origin->packageRelativePath),
                ];
            }
        }

        ksort($result, SORT_STRING);

        return $result;
    }

    private function relativePath(string $path): string
    {
        $path = Path::normalize($path);

        if (Path::isAbsolute($path) || $path === '..' || str_starts_with($path, '../')) {
            throw new \RuntimeException('A dependency index path must be package-relative.');
        }

        return str_replace('\\', '/', $path);
    }

    private function prepareOutput(string $outputDirectory): void
    {
        if (file_exists($outputDirectory) && (!is_dir($outputDirectory) || is_link($outputDirectory))) {
            throw new \RuntimeException('The dependency index output must be a regular directory.');
        }

        if (!is_dir($outputDirectory . '/packages')
            && !mkdir($outputDirectory . '/packages', 0777, true)
            && !is_dir($outputDirectory . '/packages')) {
            throw new \RuntimeException('The dependency index output directory could not be created.');
        }
    }

    private function writeFile(string $root, string $relativePath, string $contents): void
    {
        $path = Path::join($root, $relativePath);

        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new \RuntimeException(sprintf('Dependency index file "%s" could not be written.', $relativePath));
        }
    }
}
