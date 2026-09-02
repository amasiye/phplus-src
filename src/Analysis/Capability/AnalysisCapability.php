<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Capability;

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;

final readonly class AnalysisCapability
{
    /**
     * @param list<DiagnosticCode> $diagnosticCodes
     * @param list<string> $fixtureEvidence
     */
    public function __construct(
        public string $id,
        public string $name,
        public CapabilityCategory $category,
        public CapabilityRequirement $requirement,
        public CompilerCoverage $compilerCoverage,
        public SupplementalCoverage $supplementalCoverage,
        public array $diagnosticCodes,
        public array $fixtureEvidence,
        public string $notes,
        public string $migrationSlice,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9-]*)+$/', $id) !== 1) {
            throw new \InvalidArgumentException('Analysis capability identifiers must be stable dotted identifiers.');
        }

        if ($name === '' || $fixtureEvidence === [] || $notes === '' || $migrationSlice === '') {
            throw new \InvalidArgumentException('Analysis capability metadata must be complete.');
        }
    }
}
