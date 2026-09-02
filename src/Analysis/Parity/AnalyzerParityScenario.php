<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Parity;

final readonly class AnalyzerParityScenario
{
    public const int SCHEMA_VERSION = 3;

    /**
     * @param array<string, string> $sources
     * @param array<string, string> $stubs
     * @param array<string, string> $projectFiles
     * @param list<string> $expectedCompilerDiagnostics
     * @param list<string> $expectedRequiredFullDiagnostics
     * @param list<string> $expectedSupplementalFullDiagnostics
     * @param list<string> $expectedOptionalDiagnostics
     */
    public function __construct(
        public string $id,
        public string $capabilityId,
        public array $sources,
        public array $stubs,
        public array $projectFiles,
        public ?string $selection,
        public array $expectedCompilerDiagnostics,
        public array $expectedRequiredFullDiagnostics,
        public array $expectedSupplementalFullDiagnostics,
        public array $expectedOptionalDiagnostics,
        public bool $releaseBlocking,
        public ?OracleDisagreement $expectedDisagreement,
        public bool $backendUnavailable = false,
        public bool $portableDependencyIndex = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $id) !== 1) {
            throw new \InvalidArgumentException('Analyzer parity scenario identifiers must be stable kebab-case identifiers.');
        }

        if ($capabilityId === '' || $sources === []) {
            throw new \InvalidArgumentException('Analyzer parity scenarios require a capability and source files.');
        }

        foreach ([...$sources, ...$stubs] as $path => $contents) {
            if (
                $path === ''
                || Pathname::isUnsafe($path)
                || (!str_ends_with(strtolower($path), '.ppphp') && !str_ends_with(strtolower($path), '.php'))
            ) {
                throw new \InvalidArgumentException('Analyzer parity source paths must be relative PHP or ++PHP files.');
            }
        }

        foreach ($projectFiles as $path => $contents) {
            if ($path === '' || Pathname::isUnsafe($path)) {
                throw new \InvalidArgumentException('Analyzer parity project file paths must be relative and contained.');
            }
        }

        foreach ([
            ...$expectedCompilerDiagnostics,
            ...$expectedRequiredFullDiagnostics,
            ...$expectedSupplementalFullDiagnostics,
            ...$expectedOptionalDiagnostics,
        ] as $code) {
            if (preg_match('/^P[0-9]{4}$/', $code) !== 1) {
                throw new \InvalidArgumentException('Analyzer parity expectations must use stable diagnostic codes.');
            }
        }
    }
}
