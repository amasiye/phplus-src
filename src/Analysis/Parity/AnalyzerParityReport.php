<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Parity;

final readonly class AnalyzerParityReport
{
    /**
     * @param array{
     *   version: int,
     *   catalogVersion: int,
     *   compiler: array{name: string, version: string, loweringFormatVersion: int},
     *   scenarioCount: int,
     *   capabilityCount: int,
     *   coverage: array{complete: int, partial: int, backendOnly: int},
     *   fullParity: bool,
     *   requiredGaps: list<string>,
     *   unexpectedCompilerDiagnostics: list<array<string, mixed>>,
     *   unexpectedFullDiagnostics: list<array<string, mixed>>,
     *   expectationFailures: list<array<string, mixed>>,
     *   scenarios: list<array<string, mixed>>
     * } $payload
     */
    public function __construct(public array $payload) {}

    public function hasUnexpectedResults(): bool
    {
        return $this->payload['unexpectedCompilerDiagnostics'] !== []
            || $this->payload['unexpectedFullDiagnostics'] !== []
            || $this->payload['expectationFailures'] !== [];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    public function toMarkdown(): string
    {
        $coverage = $this->payload['coverage'];

        return implode("\n", [
            '# ++PHP analyzer parity',
            '',
            sprintf('- Catalog version: %d', $this->payload['catalogVersion']),
            sprintf('- Compiler version: %s', $this->payload['compiler']['version']),
            sprintf('- Scenarios: %d', $this->payload['scenarioCount']),
            sprintf('- Capabilities: %d', $this->payload['capabilityCount']),
            sprintf('- Compiler coverage: %d complete, %d partial, %d backend-only', $coverage['complete'], $coverage['partial'], $coverage['backendOnly']),
            sprintf('- Required compiler-only gaps: %d', count($this->payload['requiredGaps'])),
            sprintf('- Unexpected compiler diagnostics: %d', count($this->payload['unexpectedCompilerDiagnostics'])),
            sprintf('- Unexpected full diagnostics: %d', count($this->payload['unexpectedFullDiagnostics'])),
            sprintf('- Expectation failures: %d', count($this->payload['expectationFailures'])),
            '',
        ]);
    }
}
