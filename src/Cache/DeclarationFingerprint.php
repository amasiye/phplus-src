<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cache;

use Atatusoft\Ppphp\Analysis\DeclarationContextEmitter;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectCheckResult;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;

final readonly class DeclarationFingerprint
{
    public function __construct(private DeclarationContextEmitter $emitter = new DeclarationContextEmitter()) {}

    public function calculate(
        Project $project,
        ProjectCheckResult $check,
        ?CacheStore $store = null,
        ?ProjectInputSnapshot $snapshot = null,
    ): ?string
    {
        if ($check->parseResult === null) {
            return null;
        }

        $files = [];

        foreach ($project->sources as $source) {
            $parsed = $check->parseResult->findParsedFile($source->path)
                ?? $check->declarationContext?->findParsedFile($source->path);

            if ($parsed === null) {
                return null;
            }

            $sourceHash = 'sha256:' . hash('sha256', $parsed->sourceFile->contents);
            $path = str_replace('\\', '/', Path::normalize($source->displayPath));
            $representation = $store === null || $snapshot === null
                ? null
                : $this->loadRepresentation($store, $snapshot, $path, $source->kind->value, $sourceHash);

            if ($representation === null) {
                $declarations = $this->emitter->emitPortable($parsed);
                $extensions = $this->extensionDeclarations($parsed);

                if ($store !== null && $snapshot !== null) {
                    $this->storeRepresentation(
                        $store,
                        $snapshot,
                        $path,
                        $source->kind->value,
                        $sourceHash,
                        $declarations,
                        $extensions,
                    );
                }
            } else {
                [$declarations, $extensions] = $representation;
            }

            $files[] = [
                'declarations' => 'sha256:' . hash('sha256', $declarations),
                'extensions' => $extensions,
                'kind' => $source->kind->value,
                'path' => $path,
            ];
        }

        return 'sha256:' . hash('sha256', CanonicalJson::encode($files));
    }

    /** @return array{string, list<array{kind: string, text: string}>}|null */
    private function loadRepresentation(
        CacheStore $store,
        ProjectInputSnapshot $snapshot,
        string $path,
        string $kind,
        string $sourceHash,
    ): ?array {
        $key = $this->representationKey($snapshot, $path, $kind, $sourceHash);
        $record = $store->readRecord('compiler', 'declaration-unit', $key);

        if ($record === null) {
            return null;
        }

        $expectedKeys = [
            'declarationBlob',
            'declarationFormatVersion',
            'declarationHash',
            'extensions',
            'kind',
            'path',
            'sourceHash',
        ];

        if (array_keys($record) !== $expectedKeys
            || ($record['declarationFormatVersion'] ?? null) !== CacheFormat::DECLARATION
            || ($record['kind'] ?? null) !== $kind
            || ($record['path'] ?? null) !== $path
            || ($record['sourceHash'] ?? null) !== $sourceHash
            || !is_string($record['declarationBlob'] ?? null)
            || !is_string($record['declarationHash'] ?? null)
            || !$this->validExtensions($record['extensions'] ?? null)) {
            $store->invalidateRecord('compiler', 'declaration-unit', $key);

            return null;
        }

        $declarations = $store->readBlob($record['declarationBlob']);

        if ($declarations === null
            || !hash_equals($record['declarationHash'], 'sha256:' . hash('sha256', $declarations))) {
            $store->invalidateRecord('compiler', 'declaration-unit', $key);

            return null;
        }

        /** @var list<array{kind: string, text: string}> $extensions */
        $extensions = $record['extensions'];

        return [$declarations, $extensions];
    }

    /** @param list<array{kind: string, text: string}> $extensions */
    private function storeRepresentation(
        CacheStore $store,
        ProjectInputSnapshot $snapshot,
        string $path,
        string $kind,
        string $sourceHash,
        string $declarations,
        array $extensions,
    ): void {
        $blob = $store->writeBlob($declarations);

        if ($blob === null) {
            return;
        }

        $store->writeRecord(
            'compiler',
            'declaration-unit',
            $this->representationKey($snapshot, $path, $kind, $sourceHash),
            [
                'declarationBlob' => $blob,
                'declarationFormatVersion' => CacheFormat::DECLARATION,
                'declarationHash' => 'sha256:' . hash('sha256', $declarations),
                'extensions' => $extensions,
                'kind' => $kind,
                'path' => $path,
                'sourceHash' => $sourceHash,
            ],
        );
    }

    private function representationKey(
        ProjectInputSnapshot $snapshot,
        string $path,
        string $kind,
        string $sourceHash,
    ): CacheKey {
        $compiler = $snapshot->inputs['compiler'] ?? null;
        $formats = $snapshot->inputs['formats'] ?? null;

        return CacheKey::create('declaration-unit', [
            'compilerBuildIdentity' => is_array($compiler) ? ($compiler['buildIdentity'] ?? null) : null,
            'declarationFormatVersion' => CacheFormat::DECLARATION,
            'dependencyDeclarationFormatVersion' => is_array($formats) ? ($formats['declaration'] ?? null) : null,
            'kind' => $kind,
            'path' => $path,
            'sourceHash' => $sourceHash,
        ]);
    }

    private function validExtensions(mixed $extensions): bool
    {
        if (!is_array($extensions) || !array_is_list($extensions)) {
            return false;
        }

        foreach ($extensions as $extension) {
            if (!is_array($extension)
                || array_keys($extension) !== ['kind', 'text']
                || !is_string($extension['kind'] ?? null)
                || !is_string($extension['text'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{kind: string, text: string}> */
    private function extensionDeclarations(ParsedFile $file): array
    {
        $declarations = [];

        foreach ($file->extensionSyntax->genericDeclarations as $declaration) {
            $declarations[] = ['kind' => 'generic-declaration', 'text' => $declaration->span->text];
        }

        foreach ($file->extensionSyntax->genericTypes as $type) {
            $declarations[] = ['kind' => 'generic-type', 'text' => $type->span->text];
        }

        foreach ($file->extensionSyntax->throwsClauses as $clause) {
            $declarations[] = ['kind' => 'throws-clause', 'text' => $clause->span->text];
        }

        return $declarations;
    }
}
