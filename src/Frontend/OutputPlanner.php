<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Project\Project;
use Amasiye\Phplus\Project\ProjectSource;
use Amasiye\Phplus\Project\SourceSet;
use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Support\Path;

final readonly class OutputPlanner
{
    public function __construct(private OutputPathResolver $resolver = new OutputPathResolver()) {}

    public function plan(Project $project, SourceSet $emissionSources): OutputPlanResult
    {
        $diagnostics = new DiagnosticBag();
        /** @var array<string, array{path: string, sources: list<ProjectSource>}> $outputs */
        $outputs = [];

        foreach ($project->sources->ofKind(FileKind::Phplus) as $source) {
            $outputPath = $this->resolver->resolve($project->configuration, $source);
            $key = Path::comparisonKey($outputPath);
            $outputs[$key] ??= ['path' => $outputPath, 'sources' => []];
            $outputs[$key]['sources'][] = $source;
        }

        ksort($outputs, SORT_STRING);

        foreach ($outputs as $output) {
            if (count($output['sources']) < 2 || !$this->containsSelected($output['sources'], $emissionSources)) {
                continue;
            }

            $paths = array_map(
                static fn (ProjectSource $source): string => Path::relativeTo($source->path, $project->configuration->projectRoot),
                $output['sources'],
            );
            sort($paths, SORT_STRING);
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::OutputPathCollision,
                Severity::Error,
                'Generated PHP Output Path Collision',
                sprintf(
                    'The sources %s all map to "%s".',
                    implode(', ', array_map(static fn (string $path): string => '"' . $path . '"', $paths)),
                    Path::relativeTo($output['path'], $project->configuration->projectRoot),
                ),
                help: 'Change the source-root layout so each PHPlus source has a unique generated PHP path.',
            ));
        }

        if ($diagnostics->hasErrors()) {
            return new OutputPlanResult(null, $diagnostics);
        }

        $entries = [];

        foreach ($emissionSources as $source) {
            $entries[] = new OutputPlanEntry(
                $source,
                $this->resolver->resolve($project->configuration, $source),
            );
        }

        usort($entries, static fn (OutputPlanEntry $left, OutputPlanEntry $right): int =>
            (Path::comparisonKey($left->outputPath) <=> Path::comparisonKey($right->outputPath))
            ?: (Path::comparisonKey($left->source->path) <=> Path::comparisonKey($right->source->path)));

        return new OutputPlanResult(new OutputPlan($entries), $diagnostics);
    }

    /** @param list<ProjectSource> $sources */
    private function containsSelected(array $sources, SourceSet $selected): bool
    {
        foreach ($sources as $source) {
            if ($selected->contains($source)) {
                return true;
            }
        }

        return false;
    }
}
