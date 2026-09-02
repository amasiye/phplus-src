<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

final readonly class PreparedAnalysis
{
    /**
     * @param array{version: int, diagnostics: list<mixed>, summary: array{errors: int, warnings: int, notes: int}} $diagnostics
     * @param list<string>|null $phpStanCommand
     */
    public function __construct(
        public string $requestId,
        public string $status,
        public array $diagnostics,
        public ?AnalysisContinuation $continuation = null,
        public ?array $phpStanCommand = null,
        public ?string $phpStanWorkingDirectory = null,
        public ?string $phpStanResultPath = null,
    ) {
        if (!in_array($status, ['prepared', 'diagnostics'], true)) {
            throw new \InvalidArgumentException('The Prepare Analysis status is invalid.');
        }

        $hasPreparedMetadata = $continuation !== null
            && $phpStanCommand !== null
            && $phpStanWorkingDirectory !== null
            && $phpStanResultPath !== null;

        if (($status === 'prepared') !== $hasPreparedMetadata) {
            throw new \InvalidArgumentException('A prepared analysis requires a continuation and PHPStan command.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => PrepareAnalysisRequest::VERSION,
            'requestId' => $this->requestId,
            'action' => 'prepare',
            'status' => $this->status,
            'diagnostics' => $this->diagnostics,
            'continuation' => $this->continuation?->toArray(),
            'phpStan' => $this->phpStanCommand === null ? null : [
                'command' => $this->phpStanCommand,
                'workingDirectory' => $this->phpStanWorkingDirectory,
                'resultPath' => $this->phpStanResultPath,
            ],
        ];
    }
}
