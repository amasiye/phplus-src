<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectSource;
use Amasiye\Ppphp\Project\SourceSet;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Support\Path;

final readonly class OutputPlanner
{
    public function __construct(private OutputPathResolver $resolver = new OutputPathResolver()) {}

    public function plan(Project $project, SourceSet $emissionSources): OutputPlanResult
    {
        $diagnostics = new DiagnosticBag();
        /** @var array<string, array{path: string, sources: list<ProjectSource>}> $outputs */
        $outputs = [];

        foreach ($project->sources->filterByKind(FileKind::Ppp) as $source) {
            $outputPath = $this->resolver->resolve($project->configuration, $source);
            $key = Path::buildComparisonKey($outputPath);
            $outputs[$key] ??= ['path' => $outputPath, 'sources' => []];
            $outputs[$key]['sources'][] = $source;
        }

        ksort($outputs, SORT_STRING);

        foreach ($outputs as $output) {
            if (count($output['sources']) < 2 || !$this->containsSelected($output['sources'], $emissionSources)) {
                continue;
            }

            $paths = array_map(
                static fn (ProjectSource $source): string => Path::resolveRelativeTo($source->path, $project->configuration->projectRoot),
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
                    Path::resolveRelativeTo($output['path'], $project->configuration->projectRoot),
                ),
                help: 'Change the source-root layout so each ++PHP source has a unique generated PHP path.',
            ));
        }

        if ($diagnostics->hasErrors) {
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
            (Path::buildComparisonKey($left->outputPath) <=> Path::buildComparisonKey($right->outputPath))
            ?: (Path::buildComparisonKey($left->source->path) <=> Path::buildComparisonKey($right->source->path)));

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
