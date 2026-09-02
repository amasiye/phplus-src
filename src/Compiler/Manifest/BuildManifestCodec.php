<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Manifest;

use Amasiye\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Support\Path;

final class BuildManifestCodec
{
    public function serialize(BuildManifest $manifest): string
    {
        $files = $manifest->files;
        usort($files, static fn (BuildManifestEntry $left, BuildManifestEntry $right): int =>
            (strtolower(Path::normalize($left->output)) <=> strtolower(Path::normalize($right->output)))
            ?: ($left->source <=> $right->source));
        $data = [
            'formatVersion' => BuildManifest::FORMAT_VERSION,
            'compiler' => [
                'buildIdentity' => $manifest->compilerBuildIdentity,
                'name' => $manifest->compilerName,
                'version' => $manifest->compilerVersion,
            ],
            'loweringFormatVersion' => $manifest->loweringFormatVersion,
            'targetPhpVersion' => $manifest->targetPhpVersion,
            'configurationFingerprint' => $manifest->configurationFingerprint,
            'completeProject' => $manifest->completeProject,
            'files' => array_map(static fn (BuildManifestEntry $entry): array => [
                'source' => Path::normalize($entry->source),
                'output' => Path::normalize($entry->output),
                'sourceKind' => $entry->sourceKind->value,
                'operation' => $entry->operation->value,
                'sourceHash' => $entry->sourceHash,
                'outputHash' => $entry->outputHash,
                'sourceMap' => Path::normalize($entry->sourceMap),
                'mode' => $entry->mode,
            ], $files),
        ];

        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    public function parse(string $json): BuildManifest
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The build manifest is not valid JSON.', previous: $exception);
        }

        if (!is_array($data)
            || array_is_list($data)
            || array_keys($data) !== [
                'formatVersion',
                'compiler',
                'loweringFormatVersion',
                'targetPhpVersion',
                'configurationFingerprint',
                'completeProject',
                'files',
            ]
            || ($data['formatVersion'] ?? null) !== BuildManifest::FORMAT_VERSION) {
            throw new \InvalidArgumentException('The build manifest format version is unsupported.');
        }

        $compiler = $data['compiler'] ?? null;
        $files = $data['files'] ?? null;

        if (
            !is_array($compiler)
            || array_is_list($compiler)
            || array_keys($compiler) !== ['buildIdentity', 'name', 'version']
            || !is_string($compiler['name'] ?? null)
            || $compiler['name'] === ''
            || !is_string($compiler['version'] ?? null)
            || $compiler['version'] === ''
            || !is_string($compiler['buildIdentity'] ?? null)
            || !$this->isHash($compiler['buildIdentity'])
            || !is_int($data['loweringFormatVersion'] ?? null)
            || $data['loweringFormatVersion'] < 1
            || !is_string($data['targetPhpVersion'] ?? null)
            || $data['targetPhpVersion'] === ''
            || !is_string($data['configurationFingerprint'] ?? null)
            || !$this->isHash($data['configurationFingerprint'])
            || !is_bool($data['completeProject'] ?? null)
            || !is_array($files)
            || !array_is_list($files)
        ) {
            throw new \InvalidArgumentException('The build manifest structure is invalid.');
        }

        $entries = [];
        $sources = [];
        $outputs = [];
        $sourceMaps = [];

        foreach ($files as $file) {
            if (!is_array($file)
                || array_is_list($file)
                || array_keys($file) !== [
                    'source',
                    'output',
                    'sourceKind',
                    'operation',
                    'sourceHash',
                    'outputHash',
                    'sourceMap',
                    'mode',
                ]) {
                throw new \InvalidArgumentException('A build manifest file entry is invalid.');
            }

            foreach (['source', 'output', 'sourceKind', 'operation', 'sourceHash', 'outputHash', 'sourceMap'] as $property) {
                if (!is_string($file[$property] ?? null) || $file[$property] === '') {
                    throw new \InvalidArgumentException(sprintf('Build manifest property "%s" is invalid.', $property));
                }
            }

            $mode = $file['mode'] ?? null;

            if ($mode !== null && (!is_string($mode) || preg_match('/^[0-7]{4}$/D', $mode) !== 1)) {
                throw new \InvalidArgumentException('A build manifest file mode is invalid.');
            }

            if (!$this->isHash($file['sourceHash']) || !$this->isHash($file['outputHash'])) {
                throw new \InvalidArgumentException('A build manifest content hash is invalid.');
            }

            $this->validateRelativePath($file['source']);
            $this->validateRelativePath($file['output'], applicationOutput: true);
            $this->validateRelativePath($file['sourceMap'], sourceMap: true);
            $kind = FileKind::tryFrom($file['sourceKind']);
            $operation = OutputOperation::tryFrom($file['operation']);

            if (
                !in_array($kind, [FileKind::Ppphp, FileKind::Php], true)
                || $operation === null
                || ($kind === FileKind::Ppphp) !== ($operation === OutputOperation::Compile)
            ) {
                throw new \InvalidArgumentException('A build manifest source kind or operation is invalid.');
            }

            $sourceKey = strtolower(Path::normalize($file['source']));
            $outputKey = strtolower(Path::normalize($file['output']));
            $sourceMapKey = strtolower(Path::normalize($file['sourceMap']));

            if (isset($sources[$sourceKey]) || isset($outputs[$outputKey]) || isset($sourceMaps[$sourceMapKey])) {
                throw new \InvalidArgumentException('The build manifest contains a duplicate source, output, or source-map path.');
            }

            if ($sourceMapKey !== strtolower('.ppphp/source-maps/' . $outputKey . '.map.json')) {
                throw new \InvalidArgumentException('A build manifest source-map path does not match its output.');
            }

            $sources[$sourceKey] = true;
            $outputs[$outputKey] = true;
            $sourceMaps[$sourceMapKey] = true;
            $entries[] = new BuildManifestEntry(
                Path::normalize($file['source']),
                Path::normalize($file['output']),
                $kind,
                $operation,
                $file['sourceHash'],
                $file['outputHash'],
                Path::normalize($file['sourceMap']),
                $mode,
            );
        }

        $manifest = new BuildManifest(
            $compiler['name'],
            $compiler['version'],
            $compiler['buildIdentity'],
            $data['loweringFormatVersion'],
            $data['targetPhpVersion'],
            $data['configurationFingerprint'],
            $data['completeProject'],
            $entries,
        );

        if ($this->serialize($manifest) !== $json) {
            throw new \InvalidArgumentException('The build manifest is not canonically serialized.');
        }

        return $manifest;
    }

    private function validateRelativePath(
        string $path,
        bool $applicationOutput = false,
        bool $sourceMap = false,
    ): void
    {
        $segments = explode('/', str_replace('\\', '/', $path));
        $normalized = Path::normalize($path);

        if (str_contains($path, "\0") || Path::isAbsolute($path) || in_array('..', $segments, true) || $normalized === '.') {
            throw new \InvalidArgumentException('A build manifest path escapes the output root.');
        }

        if ($sourceMap && !str_starts_with(strtolower($normalized), '.ppphp/source-maps/')) {
            throw new \InvalidArgumentException('A source-map path is outside the compiler metadata root.');
        }

        if ($applicationOutput && str_starts_with(strtolower($normalized), '.ppphp/')) {
            throw new \InvalidArgumentException('An application output collides with compiler metadata.');
        }
    }

    private function isHash(string $value): bool
    {
        return preg_match('/^sha256:[a-f0-9]{64}$/D', $value) === 1;
    }
}
