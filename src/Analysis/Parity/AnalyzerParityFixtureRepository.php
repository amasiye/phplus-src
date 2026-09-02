<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Parity;

final readonly class AnalyzerParityFixtureRepository
{
    /** @return list<AnalyzerParityScenario> */
    public function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('The analyzer parity fixture manifest does not exist.');
        }

        $values = require $path;

        if (!is_array($values) || !array_is_list($values)) {
            throw new \RuntimeException('The analyzer parity fixture manifest must return a list.');
        }

        $scenarios = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new \RuntimeException('Every analyzer parity fixture must be an array.');
            }

            $scenarios[] = $this->createScenario($value);
        }

        usort($scenarios, static fn (AnalyzerParityScenario $left, AnalyzerParityScenario $right): int => $left->id <=> $right->id);

        return $scenarios;
    }

    /** @param array<mixed> $value */
    private function createScenario(array $value): AnalyzerParityScenario
    {
        $id = $value['id'] ?? null;
        $capabilityId = $value['capabilityId'] ?? null;
        $sources = $value['sources'] ?? null;
        $stubs = $value['stubs'] ?? [];
        $projectFiles = $value['projectFiles'] ?? [];
        $selection = $value['selection'] ?? null;
        $expectedCompiler = $value['expectedCompilerDiagnostics'] ?? null;
        $expectedRequiredFull = $value['expectedRequiredFullDiagnostics'] ?? null;
        $expectedSupplementalFull = $value['expectedSupplementalFullDiagnostics'] ?? null;
        $expectedOptional = $value['expectedOptionalDiagnostics'] ?? null;
        $releaseBlocking = $value['releaseBlocking'] ?? null;
        $expectedDisagreement = $value['expectedDisagreement'] ?? null;
        $backendUnavailable = $value['backendUnavailable'] ?? false;
        $portableDependencyIndex = $value['portableDependencyIndex'] ?? false;

        if (
            !is_string($id)
            || !is_string($capabilityId)
            || !is_array($sources)
            || !is_array($stubs)
            || !is_array($projectFiles)
            || ($selection !== null && !is_string($selection))
            || !is_array($expectedCompiler)
            || !array_is_list($expectedCompiler)
            || !is_array($expectedRequiredFull)
            || !array_is_list($expectedRequiredFull)
            || !is_array($expectedSupplementalFull)
            || !array_is_list($expectedSupplementalFull)
            || !is_array($expectedOptional)
            || !array_is_list($expectedOptional)
            || !is_bool($releaseBlocking)
            || ($expectedDisagreement !== null && !is_string($expectedDisagreement))
            || !is_bool($backendUnavailable)
            || !is_bool($portableDependencyIndex)
        ) {
            throw new \RuntimeException(sprintf('Analyzer parity fixture "%s" is malformed.', is_string($id) ? $id : '<unknown>'));
        }

        $sourceMap = $this->readFiles($sources, $id, 'sources');
        $stubMap = $this->readFiles($stubs, $id, 'stubs');
        $projectFileMap = $this->readFiles($projectFiles, $id, 'project files');

        $compilerCodes = $this->readCodes($expectedCompiler, $id);
        $requiredFullCodes = $this->readCodes($expectedRequiredFull, $id);
        $supplementalFullCodes = $this->readCodes($expectedSupplementalFull, $id);
        $optionalCodes = $this->readCodes($expectedOptional, $id);

        try {
            $disagreement = $expectedDisagreement === null
                ? null
                : OracleDisagreement::from($expectedDisagreement);
        } catch (\ValueError $exception) {
            throw new \RuntimeException(sprintf('Analyzer parity fixture "%s" has an invalid disagreement category.', $id), previous: $exception);
        }

        return new AnalyzerParityScenario(
            $id,
            $capabilityId,
            $sourceMap,
            $stubMap,
            $projectFileMap,
            $selection,
            $compilerCodes,
            $requiredFullCodes,
            $supplementalFullCodes,
            $optionalCodes,
            $releaseBlocking,
            $disagreement,
            $backendUnavailable,
            $portableDependencyIndex,
        );
    }

    /**
     * @param array<mixed> $values
     * @return array<string, string>
     */
    private function readFiles(array $values, string $id, string $kind): array
    {
        $files = [];

        foreach ($values as $path => $contents) {
            if (!is_string($path) || !is_string($contents)) {
                throw new \RuntimeException(sprintf('Analyzer parity fixture "%s" has malformed %s.', $id, $kind));
            }

            $files[$path] = $contents;
        }

        return $files;
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private function readCodes(array $values, string $id): array
    {
        $codes = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \RuntimeException(sprintf('Analyzer parity fixture "%s" has a non-string diagnostic code.', $id));
            }

            $codes[] = $value;
        }

        sort($codes, SORT_STRING);

        return $codes;
    }
}
