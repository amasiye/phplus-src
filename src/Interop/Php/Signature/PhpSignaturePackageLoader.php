<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Php\Signature;

use Amasiye\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Amasiye\Ppphp\Analysis\Declaration\DeclarationReferenceCollector;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\Enumerations\ParseMode;
use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\CanonicalJson;
use Amasiye\Ppphp\Support\Path;

final class PhpSignaturePackageLoader
{
    /** @var array<string, array<string, mixed>> */
    private array $verifiedManifests = [];

    public function __construct(
        private readonly ?string $resourceRoot = null,
        private readonly PhpSignaturePackageVerifier $verifier = new PhpSignaturePackageVerifier(),
        private readonly PpphpParser $parser = new PpphpParser(),
        private readonly DeclarationReferenceCollector $references = new DeclarationReferenceCollector(),
    ) {}

    /** @param iterable<ParsedFile> $projectFiles */
    public function load(string $target, iterable $projectFiles): ProjectParseResult
    {
        $diagnostics = new DiagnosticBag();
        $package = $this->packagePath($target);

        try {
            $this->verifiedManifests[$target] ??= $this->verifier->verify($package, $target);
            $symbols = $this->json($package . '/symbols.json');
            $references = $this->references->collect($projectFiles);
            $modules = ['core' => true];
            $this->addModules($modules, $symbols, $references);
            $parsedFiles = [];
            $sourceFiles = [];
            $loadedModules = [];

            while (($pending = array_values(array_diff(array_keys($modules), array_keys($loadedModules)))) !== []) {
                sort($pending, SORT_STRING);

                foreach ($pending as $module) {
                    $loadedModules[$module] = true;
                    $shard = $this->json($package . '/extensions/' . $module . '.json');
                    $documents = $shard['documents'] ?? null;

                    if (!is_array($documents) || !array_is_list($documents)) {
                        throw new \RuntimeException(sprintf('PHP signature module "%s" is malformed.', $module));
                    }

                    foreach ($documents as $document) {
                        if (!is_array($document)
                            || !is_string($document['path'] ?? null)
                            || !is_string($document['source'] ?? null)) {
                            throw new \RuntimeException(sprintf('PHP signature module "%s" contains an invalid document.', $module));
                        }

                        $sourceFile = new SourceFile(
                            $package . '/declarations/' . $document['path'],
                            sprintf('<PHP %s platform>/%s', $target, $document['path']),
                            FileKind::Stub,
                            $document['source'],
                            DeclarationOrigin::PhpPlatform,
                        );
                        $result = $this->parser->parse($sourceFile, ParseMode::Php);

                        if ($result->parsedFile === null || $result->diagnostics->hasErrors) {
                            throw new \RuntimeException(sprintf(
                                'Normalized PHP signature document "%s" could not be loaded.',
                                $document['path'],
                            ));
                        }

                        $key = Path::buildComparisonKey($sourceFile->path);
                        $sourceFiles[$key] = $sourceFile;
                        $parsedFiles[$key] = $result->parsedFile;
                    }
                }

                $closure = $this->references->collect($parsedFiles);
                $this->addModules($modules, $symbols, $closure);
            }

            ksort($parsedFiles, SORT_STRING);
            ksort($sourceFiles, SORT_STRING);

            return new ProjectParseResult($parsedFiles, $sourceFiles, $diagnostics);
        } catch (\Throwable $exception) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::PhpSignaturePackageInvalid,
                sprintf('The compiler PHP %s signature package could not be trusted.', $target),
                help: 'Reinstall the compiler from a verified distribution.',
                debug: ['message' => $exception->getMessage()],
            ));

            return new ProjectParseResult([], [], $diagnostics);
        }
    }

    private function packagePath(string $target): string
    {
        $root = $this->resourceRoot ?? dirname(__DIR__, 4) . '/resources/php-signatures';

        return rtrim(str_replace('\\', '/', $root), '/') . '/' . $target;
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Required PHP signature resource "%s" is unavailable.', basename($path)));
        }

        $decoded = CanonicalJson::decode($contents);

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException(sprintf('PHP signature resource "%s" is malformed.', basename($path)));
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException(sprintf('PHP signature resource "%s" has an invalid key.', basename($path)));
            }

            $object[$key] = $value;
        }

        return $object;
    }

    /**
     * @param array<string, true> $modules
     * @param array<string, mixed> $symbols
     * @param array{classes: list<string>, functions: list<string>, constants: list<string>} $references
     */
    private function addModules(array &$modules, array $symbols, array $references): void
    {
        foreach ($references as $bucket => $names) {
            $index = $symbols[$bucket] ?? null;

            if (!is_array($index)) {
                throw new \RuntimeException(sprintf('PHP signature %s index is malformed.', $bucket));
            }

            foreach ($names as $name) {
                $key = $bucket === 'constants' ? $name : strtolower($name);
                $locations = $index[$key] ?? [];

                if (!is_array($locations)) {
                    throw new \RuntimeException(sprintf('PHP signature index entry "%s" is malformed.', $name));
                }

                foreach ($locations as $location) {
                    if (!is_array($location) || !is_string($location['module'] ?? null)) {
                        throw new \RuntimeException(sprintf('PHP signature index location "%s" is malformed.', $name));
                    }

                    $modules[$location['module']] = true;
                }
            }
        }
    }
}
