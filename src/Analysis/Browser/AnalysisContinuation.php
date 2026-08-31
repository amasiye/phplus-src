<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Browser;

final readonly class AnalysisContinuation
{
    /**
     * @param array{name: string, version: string, loweringFormatVersion: int} $compiler
     * @param list<array{path: string, hash: string}> $sources
     * @param list<string> $selectedSources
     * @param list<array{path: string, bytes: int, hash: string}> $workspaceManifest
     * @param array{path: string, format: string, maximumBytes: int} $expectedResult
     */
    public function __construct(
        public int $version,
        public string $prepareRequestId,
        public string $operation,
        public ?string $selectedPath,
        public array $compiler,
        public array $sources,
        public string $projectConfigurationHash,
        public array $selectedSources,
        public array $workspaceManifest,
        public string $phpStanConfigurationHash,
        public array $expectedResult,
        public string $contentHash,
    ) {
        if ($version !== PrepareAnalysisRequest::VERSION) {
            throw new \InvalidArgumentException('The browser analysis continuation version is unsupported.');
        }

        if (!hash_equals(self::calculateHash($this->payload()), $contentHash)) {
            throw new \InvalidArgumentException('The browser analysis continuation content hash is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->payload(), 'contentHash' => $this->contentHash];
    }

    /** @param array<string, mixed> $payload */
    public static function calculateHash(array $payload): string
    {
        unset($payload['contentHash']);

        return ProtocolJson::hash(ProtocolJson::encodeCanonical($payload));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'version' => $this->version,
            'prepareRequestId' => $this->prepareRequestId,
            'operation' => $this->operation,
            'selectedPath' => $this->selectedPath,
            'compiler' => $this->compiler,
            'sources' => $this->sources,
            'projectConfigurationHash' => $this->projectConfigurationHash,
            'selectedSources' => $this->selectedSources,
            'workspaceManifest' => $this->workspaceManifest,
            'phpStanConfigurationHash' => $this->phpStanConfigurationHash,
            'expectedResult' => $this->expectedResult,
        ];
    }
}
