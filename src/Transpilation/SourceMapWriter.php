<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Transpilation;

use Amasiye\Ppphp\Compiler\CompilationArtifact;
use Amasiye\Ppphp\Support\Path;

final class SourceMapWriter
{
    public const int FORMAT_VERSION = 1;

    public function serialize(CompilationArtifact $artifact): string
    {
        $this->validateGeneratedMap($artifact->sourceMap, strlen($artifact->contents));
        $source = $this->normalizeRelativePath($artifact->sourceFile->displayPath, false);
        $generated = $this->normalizeRelativePath($artifact->relativeOutputPath, true);

        if (!$this->isHash($artifact->sourceHash) || !$this->isHash($artifact->outputHash)) {
            throw new \InvalidArgumentException('Production source maps require SHA-256 content hashes.');
        }

        $segments = array_map(static function (GeneratedSourceMapSegment $segment): array {
            $data = [
                'generatedStart' => $segment->generatedStart,
                'generatedEnd' => $segment->generatedEnd,
                'originalStart' => $segment->originalStart,
                'originalEnd' => $segment->originalEnd,
            ];

            if ($segment->owner !== null) {
                $data['ownerStart'] = $segment->owner->start->offset;
                $data['ownerEnd'] = $segment->owner->end->offset;
            }

            return $data;
        }, $artifact->sourceMap->segments);
        $data = [
            'formatVersion' => self::FORMAT_VERSION,
            'source' => $source,
            'generated' => $generated,
            'sourceHash' => $artifact->sourceHash,
            'generatedHash' => $artifact->outputHash,
            'generatedLength' => strlen($artifact->contents),
            'segments' => $segments,
        ];

        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @return array<mixed> */
    public function parseAndValidate(string $json, string $source, string $generated, string $sourceHash, string $generatedHash, int $generatedLength): array
    {
        $source = $this->normalizeRelativePath($source, false);
        $generated = $this->normalizeRelativePath($generated, true);

        if (!$this->isHash($sourceHash) || !$this->isHash($generatedHash) || $generatedLength < 0) {
            throw new \InvalidArgumentException('Production source-map identity is invalid.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The production source map is not valid JSON.', previous: $exception);
        }

        if (
            !is_array($data)
            || ($data['formatVersion'] ?? null) !== self::FORMAT_VERSION
            || ($data['source'] ?? null) !== $source
            || ($data['generated'] ?? null) !== $generated
            || ($data['sourceHash'] ?? null) !== $sourceHash
            || ($data['generatedHash'] ?? null) !== $generatedHash
            || ($data['generatedLength'] ?? null) !== $generatedLength
            || !is_array($data['segments'] ?? null)
        ) {
            throw new \InvalidArgumentException('The production source map does not match its manifest entry.');
        }

        $previousEnd = 0;

        foreach ($data['segments'] as $segment) {
            if (!is_array($segment)) {
                throw new \InvalidArgumentException('A production source-map segment is invalid.');
            }

            foreach (['generatedStart', 'generatedEnd', 'originalStart', 'originalEnd'] as $property) {
                if (!is_int($segment[$property] ?? null)) {
                    throw new \InvalidArgumentException('A production source-map segment offset is invalid.');
                }
            }

            if (
                $segment['generatedStart'] < $previousEnd
                || $segment['generatedEnd'] < $segment['generatedStart']
                || $segment['generatedEnd'] > $generatedLength
                || $segment['originalStart'] < 0
                || $segment['originalEnd'] < $segment['originalStart']
            ) {
                throw new \InvalidArgumentException('A production source-map segment is unordered or out of bounds.');
            }

            if (array_key_exists('ownerStart', $segment) || array_key_exists('ownerEnd', $segment)) {
                if (
                    !is_int($segment['ownerStart'] ?? null)
                    || !is_int($segment['ownerEnd'] ?? null)
                    || $segment['ownerStart'] < 0
                    || $segment['ownerEnd'] < $segment['ownerStart']
                ) {
                    throw new \InvalidArgumentException('A production source-map owner span is invalid.');
                }
            }

            $previousEnd = $segment['generatedEnd'];
        }

        return $data;
    }

    private function validateGeneratedMap(GeneratedSourceMap $map, int $generatedLength): void
    {
        if ($map->generatedLength !== $generatedLength) {
            throw new \InvalidArgumentException('The generated source-map length is invalid.');
        }

        $previousEnd = 0;

        foreach ($map->segments as $segment) {
            if (
                $segment->generatedStart < $previousEnd
                || $segment->generatedEnd < $segment->generatedStart
                || $segment->generatedEnd > $generatedLength
                || $segment->originalStart < 0
                || $segment->originalEnd < $segment->originalStart
                || $segment->originalEnd > $map->sourceFile->length
            ) {
                throw new \InvalidArgumentException('The generated source map contains an invalid segment.');
            }

            $previousEnd = $segment->generatedEnd;
        }
    }

    private function normalizeRelativePath(string $path, bool $generated): string
    {
        $segments = explode('/', str_replace('\\', '/', $path));
        $normalized = Path::normalize($path);

        if (
            str_contains($path, "\0")
            || Path::isAbsolute($path)
            || in_array('..', $segments, true)
            || $normalized === '.'
            || ($generated && str_starts_with(strtolower($normalized), '.ppphp/'))
        ) {
            throw new \InvalidArgumentException('A production source-map path is unsafe.');
        }

        return $normalized;
    }

    private function isHash(string $value): bool
    {
        return preg_match('/^sha256:[a-f0-9]{64}$/D', $value) === 1;
    }
}
