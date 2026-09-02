<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer\Index;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Analysis\Declaration\DeclarationReferenceCollector;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Interop\Composer\DependencyDeclarationProvenance;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;
use PhpParser\Node;
use PhpParser\Node\Stmt;

final readonly class DependencyDeclarationIndexReader
{
    public function __construct(
        private PpphpParser $parser = new PpphpParser(),
        private DeclarationReferenceCollector $references = new DeclarationReferenceCollector(),
        private PortableDeclarationValidator $validator = new PortableDeclarationValidator(),
    ) {}

    public function read(
        string $manifestPath,
        string $expectedTargetPhpVersion,
        ?string $expectedManifestHash = null,
    ): ProjectParseResult {
        try {
            return $this->readValid($manifestPath, $expectedTargetPhpVersion, $expectedManifestHash);
        } catch (\Throwable $exception) {
            $diagnostics = new DiagnosticBag();
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::PortableDependencyIndexInvalid,
                'The portable dependency declaration index is invalid.',
                help: 'Regenerate the complete dependency index with the current compiler and configured PHP target.',
                debug: ['message' => $exception->getMessage()],
            ));

            return new ProjectParseResult([], [], $diagnostics);
        }
    }

    private function readValid(
        string $manifestPath,
        string $expectedTargetPhpVersion,
        ?string $expectedManifestHash,
    ): ProjectParseResult {
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new \RuntimeException('The dependency index manifest is unavailable.');
        }

        $manifestRealPath = realpath($manifestPath);

        if (!is_string($manifestRealPath)) {
            throw new \RuntimeException('The dependency index manifest path cannot be canonicalized.');
        }

        $root = dirname($manifestRealPath);
        $manifestContents = $this->readFile($manifestRealPath);

        if ($expectedManifestHash !== null
            && !$this->hashMatches($manifestContents, $expectedManifestHash)) {
            throw new \RuntimeException('The dependency index manifest hash does not match the request.');
        }

        $manifestData = CanonicalJson::decode($manifestContents);

        if (CanonicalJson::encode($manifestData) !== $manifestContents) {
            throw new \RuntimeException('The dependency index manifest is not canonically serialized.');
        }

        $manifest = $this->object($manifestData, 'manifest');
        $this->properties($manifest, [
            'composerLockSha256',
            'contentIdentity',
            'counts',
            'declarationCompatibilityIdentity',
            'declarationFormatVersion',
            'formatVersion',
            'installedMetadataSha256',
            'packages',
            'producer',
            'targetPhpVersion',
        ], 'manifest');
        $this->exactInteger($manifest, 'formatVersion', DependencyDeclarationIndexWriter::FORMAT_VERSION);
        $this->exactInteger($manifest, 'declarationFormatVersion', DependencyDeclarationIndexWriter::DECLARATION_FORMAT_VERSION);
        $this->exactString($manifest, 'targetPhpVersion', $expectedTargetPhpVersion);
        $this->exactString(
            $manifest,
            'declarationCompatibilityIdentity',
            DeclarationCompatibilityIdentity::calculate(),
        );
        $producer = $this->object($manifest['producer'] ?? null, 'producer');
        $this->properties($producer, ['buildIdentity', 'identity', 'version'], 'producer');
        $this->exactString($producer, 'identity', 'atatusoft-ltd/ppphp-src');
        $this->string($producer, 'version');
        $this->hash($this->string($producer, 'buildIdentity'), true);
        $this->hash($this->string($manifest, 'contentIdentity'), true);
        $this->nullableHash($manifest['composerLockSha256'] ?? null, 'composerLockSha256');
        $this->nullableHash($manifest['installedMetadataSha256'] ?? null, 'installedMetadataSha256');
        $packages = $this->list($manifest['packages'] ?? null, 'packages');
        $parsedFiles = [];
        $sourceFiles = [];
        $classAliases = [];
        $classAliasProvenance = [];
        $prefixes = [];
        $packageNames = [];
        $declarationOwners = [];
        $totalBytes = strlen($manifestContents);
        $counts = [
            'aliases' => 0,
            'classLikes' => 0,
            'conditionalDeclarations' => 0,
            'constants' => 0,
            'documents' => 0,
            'functions' => 0,
            'staticIncludes' => 0,
        ];

        foreach ($packages as $expectedOrder => $entryValue) {
            $entry = $this->object($entryValue, 'package entry');
            $this->properties($entry, [
                'autoloadForms',
                'counts',
                'developmentOnly',
                'name',
                'order',
                'path',
                'prettyVersion',
                'reference',
                'sha256',
                'type',
                'version',
            ], 'package entry');
            $name = $this->string($entry, 'name');
            $key = strtolower($name);

            if (isset($packageNames[$key]) || ($entry['order'] ?? null) !== $expectedOrder) {
                throw new \RuntimeException('Dependency index packages are duplicated or out of order.');
            }

            $packageNames[$key] = true;
            $shardPath = $this->relativePath($this->string($entry, 'path'));
            $shardSourcePath = Path::join($root, $shardPath);
            $shardRealPath = realpath($shardSourcePath);

            if (!is_string($shardRealPath)
                || is_link($shardSourcePath)
                || !Path::contains($root, $shardRealPath)
                || !is_file($shardRealPath)) {
                throw new \RuntimeException(sprintf('Dependency index shard "%s" is unavailable or unsafe.', $shardPath));
            }

            $shardContents = $this->readFile($shardRealPath);
            $totalBytes += strlen($shardContents);

            if ($totalBytes > \Atatusoft\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader::MAXIMUM_BYTES
                || hash('sha256', $shardContents) !== ($entry['sha256'] ?? null)) {
                throw new \RuntimeException(sprintf('Dependency index shard "%s" has an invalid hash or size.', $shardPath));
            }

            $shardData = CanonicalJson::decode($shardContents);

            if (CanonicalJson::encode($shardData) !== $shardContents) {
                throw new \RuntimeException(sprintf('Dependency index shard "%s" is not canonically serialized.', $shardPath));
            }

            $shard = $this->object($shardData, 'package shard');
            $this->properties($shard, [
                'aliases',
                'autoload',
                'counts',
                'declarationFormatVersion',
                'documents',
                'formatVersion',
                'package',
                'targetPhpVersion',
            ], 'package shard');
            $this->exactInteger($shard, 'formatVersion', DependencyDeclarationIndexWriter::FORMAT_VERSION);
            $this->exactInteger($shard, 'declarationFormatVersion', DependencyDeclarationIndexWriter::DECLARATION_FORMAT_VERSION);
            $this->exactString($shard, 'targetPhpVersion', $expectedTargetPhpVersion);
            $package = $this->object($shard['package'] ?? null, 'shard package');
            $this->properties($package, [
                'developmentOnly',
                'name',
                'order',
                'prettyVersion',
                'reference',
                'type',
                'version',
            ], 'shard package');
            $version = $this->nullableString($entry, 'version');
            $prettyVersion = $this->nullableString($entry, 'prettyVersion');
            $reference = $this->nullableString($entry, 'reference');
            $this->nullableString($entry, 'type');

            if (!is_bool($entry['developmentOnly'] ?? null)) {
                throw new \RuntimeException(sprintf('Dependency package identity for "%s" is malformed.', $name));
            }

            foreach (['name', 'order', 'version', 'prettyVersion', 'reference', 'type', 'developmentOnly'] as $identityField) {
                if (($package[$identityField] ?? null) !== ($entry[$identityField] ?? null)) {
                    throw new \RuntimeException(sprintf('Dependency shard identity for "%s" is inconsistent.', $name));
                }
            }

            $autoload = $this->object($shard['autoload'] ?? null, 'autoload');
            $this->properties($autoload, [
                'classmap',
                'excludeFromClassmap',
                'files',
                'psr0',
                'psr4',
            ], 'autoload');
            $packageCounts = [
                'classLikes' => 0,
                'constants' => 0,
                'functions' => 0,
                'aliases' => 0,
                'conditionalDeclarations' => 0,
                'documents' => 0,
                'staticIncludes' => 0,
            ];
            $autoloadForms = [];
            $lastDocumentOrder = -1;

            foreach (['psr4', 'psr0'] as $form) {
                $mapping = $this->object($autoload[$form] ?? null, $form);

                foreach ($mapping as $prefix => $paths) {
                    foreach ($this->list($paths, $form . ' paths') as $path) {
                        if (!is_string($path)) {
                            throw new \RuntimeException('A dependency autoload path is invalid.');
                        }

                        $this->relativePath($path);
                    }

                    if ($prefix !== '') {
                        $prefixes[$prefix] = true;
                    }
                }
            }

            foreach (['classmap', 'files', 'excludeFromClassmap'] as $form) {
                foreach ($this->list($autoload[$form] ?? null, $form) as $path) {
                    if (!is_string($path)) {
                        throw new \RuntimeException('A dependency autoload path is invalid.');
                    }

                    $this->relativePath($path);
                }
            }

            $documents = $this->list($shard['documents'] ?? null, 'documents');

            foreach ($documents as $documentIndex => $documentValue) {
                if (count($parsedFiles) >= \Atatusoft\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader::MAXIMUM_FILES) {
                    throw new \RuntimeException('The dependency index document limit was exceeded.');
                }

                $document = $this->object($documentValue, 'document');
                $this->properties($document, [
                    'autoloadForm',
                    'conditional',
                    'counts',
                    'order',
                    'path',
                    'source',
                ], 'document');
                $relativePath = $this->relativePath($this->string($document, 'path'));
                $source = $this->string($document, 'source');
                $form = $this->string($document, 'autoloadForm');
                $conditional = $document['conditional'] ?? null;
                $order = $document['order'] ?? null;

                if (!in_array($form, ['classmap', 'files', 'include', 'psr-0', 'psr-4'], true)
                    || !is_bool($conditional)
                    || !is_int($order)
                    || $order < $lastDocumentOrder
                    || !str_ends_with($source, "\n")) {
                    throw new \RuntimeException(sprintf('A dependency document for "%s" is malformed.', $name));
                }

                $lastDocumentOrder = $order;

                $virtualPath = Path::join($root, '.declarations/' . substr(hash('sha256', $name), 0, 16) . '/' . $relativePath);
                $sourceFile = new SourceFile(
                    $virtualPath . ($conditional ? '#conditional' : ''),
                    sprintf('<Composer %s>/%s', $name, $relativePath),
                    FileKind::Php,
                    $source,
                    $conditional ? DeclarationOrigin::ConditionalComposerDependency : DeclarationOrigin::ComposerDependency,
                    new DependencyDeclarationProvenance(
                        $name,
                        $prettyVersion ?? $version,
                        $reference,
                        $relativePath,
                        $form,
                        $order,
                        $conditional,
                    ),
                );
                $parse = $this->parser->parse($sourceFile, ParseMode::Php);

                if ($parse->parsedFile === null || $parse->diagnostics->hasErrors) {
                    throw new \RuntimeException(sprintf('Dependency declaration document "%s" is invalid or contains an implementation body.', $relativePath));
                }

                $this->validator->validateStatements($parse->parsedFile->statements);

                $documentCounts = $this->references->collectDeclarations([$parse->parsedFile]);
                $actualDocumentCounts = [
                    'classLikes' => count($documentCounts['classes']),
                    'constants' => count($documentCounts['constants']),
                    'functions' => count($documentCounts['functions']),
                ];

                if (($document['counts'] ?? null) !== $actualDocumentCounts) {
                    throw new \RuntimeException(sprintf('Dependency declaration counts for "%s" do not match.', $relativePath));
                }

                foreach ($documentCounts as $kind => $names) {
                    foreach ($names as $declarationName) {
                        $declarationKey = $kind . ':' . strtolower(ltrim($declarationName, '\\'));
                        $state = $conditional ? 'conditional' : 'unconditional';

                        if (($declarationOwners[$declarationKey][$state] ?? false) === true) {
                            throw new \RuntimeException(sprintf('Dependency declaration "%s" is duplicated.', $declarationName));
                        }

                        $declarationOwners[$declarationKey][$state] = true;
                    }
                }

                foreach ($actualDocumentCounts as $countName => $count) {
                    $counts[$countName] += $count;
                    $packageCounts[$countName] += $count;
                }

                $counts['conditionalDeclarations'] += $conditional ? array_sum($actualDocumentCounts) : 0;
                $counts['staticIncludes'] += $form === 'include' ? 1 : 0;
                $counts['documents']++;
                $packageCounts['conditionalDeclarations'] += $conditional ? array_sum($actualDocumentCounts) : 0;
                $packageCounts['staticIncludes'] += $form === 'include' ? 1 : 0;
                $packageCounts['documents']++;
                $autoloadForms[$form] = true;
                $fileKey = Path::buildComparisonKey($sourceFile->path) . ':' . $documentIndex;
                $parsedFiles[$fileKey] = $parse->parsedFile;
                $sourceFiles[$fileKey] = $sourceFile;
            }

            $aliases = $this->object($shard['aliases'] ?? null, 'aliases');

            foreach ($aliases as $alias => $aliasValue) {
                $aliasDeclaration = $this->object($aliasValue, 'alias');
                $this->properties($aliasDeclaration, [
                    'autoloadForm',
                    'order',
                    'original',
                    'path',
                ], 'alias');
                $original = $this->string($aliasDeclaration, 'original');
                $aliasPath = $this->relativePath($this->string($aliasDeclaration, 'path'));
                $aliasForm = $this->string($aliasDeclaration, 'autoloadForm');
                $aliasOrder = $aliasDeclaration['order'] ?? null;

                if ($alias === ''
                    || !in_array($aliasForm, ['classmap', 'files', 'include', 'psr-0', 'psr-4'], true)
                    || !is_int($aliasOrder)
                    || $aliasOrder < 0) {
                    throw new \RuntimeException('A dependency alias is malformed.');
                }

                $aliasKey = strtolower(ltrim($alias, '\\'));

                if (array_key_exists($aliasKey, array_change_key_case($classAliases, CASE_LOWER))) {
                    throw new \RuntimeException(sprintf('Dependency alias "%s" is duplicated.', $alias));
                }

                $classAliases[$alias] = $original;
                $classAliasProvenance[$alias] = new DependencyDeclarationProvenance(
                    $name,
                    $prettyVersion ?? $version,
                    $reference,
                    $aliasPath,
                    $aliasForm,
                    $aliasOrder,
                );
            }

            $counts['aliases'] += count($aliases);
            $packageCounts['aliases'] += count($aliases);
            $shardCounts = $this->object($shard['counts'] ?? null, 'shard counts');
            $entryCounts = $this->object($entry['counts'] ?? null, 'manifest package counts');

            if (!$this->countsMatch($shardCounts, $packageCounts)
                || !$this->countsMatch($entryCounts, $packageCounts)) {
                throw new \RuntimeException(sprintf('Dependency shard counts for "%s" are inconsistent.', $name));
            }

            if ($this->list($entry['autoloadForms'] ?? null, 'autoload forms') !== array_keys($autoloadForms)) {
                throw new \RuntimeException(sprintf('Dependency autoload forms for "%s" are inconsistent.', $name));
            }
        }

        if (!$this->countsMatch($this->object($manifest['counts'] ?? null, 'manifest counts'), $counts)) {
            throw new \RuntimeException('Dependency index manifest counts do not match its shards.');
        }

        $this->validateAliases($classAliases, $parsedFiles);

        $identityPayload = CanonicalJson::encode([
            'compatibilityIdentity' => DeclarationCompatibilityIdentity::calculate(),
            'declarationFormatVersion' => DependencyDeclarationIndexWriter::DECLARATION_FORMAT_VERSION,
            'packages' => $packages,
            'producer' => $producer,
            'targetPhpVersion' => $expectedTargetPhpVersion,
        ]);

        if (($manifest['contentIdentity'] ?? null) !== 'sha256:' . hash('sha256', $identityPayload)) {
            throw new \RuntimeException('Dependency index content identity does not match its manifest.');
        }

        return new ProjectParseResult(
            $parsedFiles,
            $sourceFiles,
            new DiagnosticBag(),
            array_keys($prefixes),
            $classAliases,
            $classAliasProvenance,
        );
    }

    /**
     * @param array<string, string> $aliases
     * @param array<string, ParsedFile> $parsedFiles
     */
    private function validateAliases(array $aliases, array $parsedFiles): void
    {
        $declared = array_fill_keys(array_map(
            static fn (string $name): string => strtolower(ltrim($name, '\\')),
            $this->references->collectDeclarations($parsedFiles)['classes'],
        ), true);
        $normalized = [];

        foreach ($aliases as $alias => $original) {
            $key = strtolower(ltrim($alias, '\\'));

            if (isset($declared[$key]) || isset($normalized[$key])) {
                throw new \RuntimeException(sprintf('Dependency alias "%s" conflicts with a declaration.', $alias));
            }

            $normalized[$key] = strtolower(ltrim($original, '\\'));
        }

        foreach (array_keys($normalized) as $alias) {
            $seen = [];
            $current = $alias;

            while (isset($normalized[$current])) {
                if (isset($seen[$current])) {
                    throw new \RuntimeException(sprintf('Dependency alias "%s" forms a cycle.', $alias));
                }

                $seen[$current] = true;
                $current = $normalized[$current];
            }
        }
    }

    private function readFile(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException(sprintf('Dependency index file "%s" is not a regular file.', basename($path)));
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Dependency index file "%s" could not be opened.', basename($path)));
        }

        try {
            $stat = fstat($handle);

            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
                throw new \RuntimeException(sprintf('Dependency index file "%s" changed type.', basename($path)));
            }

            $contents = stream_get_contents($handle, \Atatusoft\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader::MAXIMUM_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if (!is_string($contents)
            || strlen($contents) > \Atatusoft\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader::MAXIMUM_BYTES
            || !str_ends_with($contents, "\n")) {
            throw new \RuntimeException(sprintf('Dependency index file "%s" is unreadable or lacks its final LF.', basename($path)));
        }

        return $contents;
    }

    private function hashMatches(string $contents, string $expected): bool
    {
        $actual = hash('sha256', $contents);

        return hash_equals($actual, str_starts_with($expected, 'sha256:') ? substr($expected, 7) : $expected);
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $name): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \RuntimeException(sprintf('Dependency index %s must be an object.', $name));
        }

        $object = [];

        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new \RuntimeException(sprintf('Dependency index %s contains a non-string property.', $name));
            }

            $object[$key] = $entry;
        }

        return $object;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $name): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException(sprintf('Dependency index %s must be a list.', $name));
        }

        return $value;
    }

    /** @param array<string, mixed> $object */
    private function string(array $object, string $property): string
    {
        $value = $object[$property] ?? null;

        if (!is_string($value) || $value === '') {
            throw new \RuntimeException(sprintf('Dependency index property "%s" must be a non-empty string.', $property));
        }

        return $value;
    }

    /** @param array<string, mixed> $object */
    private function nullableString(array $object, string $property): ?string
    {
        $value = $object[$property] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new \RuntimeException(sprintf('Dependency index property "%s" must be a string or null.', $property));
        }

        return $value;
    }

    /** @param array<string, mixed> $object */
    private function exactString(array $object, string $property, string $expected): void
    {
        if (($object[$property] ?? null) !== $expected) {
            throw new \RuntimeException(sprintf('Dependency index property "%s" is incompatible.', $property));
        }
    }

    /** @param array<string, mixed> $object */
    private function exactInteger(array $object, string $property, int $expected): void
    {
        if (($object[$property] ?? null) !== $expected) {
            throw new \RuntimeException(sprintf('Dependency index property "%s" is incompatible.', $property));
        }
    }

    private function relativePath(string $path): string
    {
        $normalized = Path::normalize($path);

        if ($normalized === '.'
            || Path::isAbsolute($normalized)
            || $normalized === '..'
            || str_starts_with($normalized, '../')
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || $normalized !== $path) {
            throw new \RuntimeException(sprintf('Dependency index path "%s" is unsafe.', $path));
        }

        return str_replace('\\', '/', $normalized);
    }

    /**
     * @param array<string, mixed> $actual
     * @param array<string, int> $expected
     */
    private function countsMatch(array $actual, array $expected): bool
    {
        $actualKeys = array_keys($actual);
        $expectedKeys = array_keys($expected);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            return false;
        }

        foreach ($expected as $name => $value) {
            if (($actual[$name] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string> $expected
     */
    private function properties(array $object, array $expected, string $name): void
    {
        $actual = array_keys($object);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new \RuntimeException(sprintf('Dependency index %s properties are incomplete or unknown.', $name));
        }
    }

    private function hash(string $value, bool $prefixed): void
    {
        $pattern = $prefixed ? '/^sha256:[a-f0-9]{64}$/D' : '/^[a-f0-9]{64}$/D';

        if (preg_match($pattern, $value) !== 1) {
            throw new \RuntimeException('A dependency index content hash is malformed.');
        }
    }

    private function nullableHash(mixed $value, string $name): void
    {
        if ($value === null) {
            return;
        }

        if (!is_string($value)) {
            throw new \RuntimeException(sprintf('Dependency index property "%s" must be a hash or null.', $name));
        }

        $this->hash($value, true);
    }
}
