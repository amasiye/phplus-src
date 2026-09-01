<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Browser;

use Amasiye\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;
use Amasiye\Ppphp\Analysis\CompilerProjectAnalysis;
use Amasiye\Ppphp\Compiler\Compiler;

final readonly class CompilerAnalysisResponse
{
    /**
     * @param array{version: int, diagnostics: list<mixed>, summary: array{errors: int, warnings: int, notes: int}}|null $diagnostics
     * @param array{code: string, message: string, limit?: string}|null $error
     */
    private function __construct(
        public ?string $requestId,
        public string $status,
        public ?array $diagnostics,
        public ?array $error,
        public ?CompilerProjectAnalysis $analysis,
    ) {}

    /** @param array{version: int, diagnostics: list<mixed>, summary: array{errors: int, warnings: int, notes: int}} $diagnostics */
    public static function complete(
        string $requestId,
        array $diagnostics,
        ?CompilerProjectAnalysis $analysis = null,
    ): self {
        return new self($requestId, 'complete', $diagnostics, null, $analysis);
    }

    public static function error(
        ?string $requestId,
        string $code,
        string $message,
        ?string $limit = null,
    ): self {
        $error = ['code' => $code, 'message' => $message];

        if ($limit !== null) {
            $error['limit'] = $limit;
        }

        return new self($requestId, 'error', null, $error, null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $base = [
            'version' => CompilerAnalysisRequest::VERSION,
            'requestId' => $this->requestId,
            'action' => 'analyze',
            'status' => $this->status,
        ];

        if ($this->status === 'error') {
            return [...$base, 'error' => $this->error];
        }

        $completeness = $this->analysis === null ? 'compilerCore' : $this->analysis->completeness->value;
        $gaps = $this->analysis === null
            ? (new AnalysisCapabilityCatalog())->uncoveredRequiredCapabilityIds
            : $this->analysis->uncoveredRequiredCapabilities;

        return [
            ...$base,
            'compiler' => [
                'name' => Compiler::NAME,
                'version' => Compiler::VERSION,
                'loweringFormatVersion' => Compiler::LOWERING_FORMAT_VERSION,
            ],
            'engine' => 'compiler',
            'completeness' => $completeness,
            'catalogVersion' => AnalysisCapabilityCatalog::VERSION,
            'fullParity' => $gaps === [],
            'uncoveredRequiredCapabilities' => $gaps,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
