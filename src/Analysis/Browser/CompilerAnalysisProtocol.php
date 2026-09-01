<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Browser;

use Amasiye\Ppphp\Analysis\CompilerProjectAnalyzer;
use Amasiye\Ppphp\Config\ProjectConfigLoader;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Project\Enumerations\SelectionMode;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectLoader;
use Amasiye\Ppphp\Project\ProjectSelector;

final readonly class CompilerAnalysisProtocol
{
    public function __construct(
        private ProjectConfigLoader $configLoader = new ProjectConfigLoader(),
        private ProjectLoader $projectLoader = new ProjectLoader(),
        private ProjectSelector $selector = new ProjectSelector(),
        private CompilerProjectAnalyzer $analyzer = new CompilerProjectAnalyzer(),
        private BrowserDiagnosticRenderer $diagnosticRenderer = new BrowserDiagnosticRenderer(),
    ) {}

    public function analyze(
        CompilerAnalysisRequest $request,
        string $workingDirectory,
        ?string $configurationPath = null,
    ): CompilerAnalysisResponse {
        $config = $this->configLoader->load($workingDirectory, $configurationPath, true);

        if (!$config->isSuccessful || $config->configuration === null) {
            return $this->diagnosticResponse($request, $config->diagnostics);
        }

        $project = $this->projectLoader->load($config->configuration);

        if (!$project->isSuccessful || $project->project === null) {
            return $this->diagnosticResponse($request, $project->diagnostics);
        }

        $resourceError = $this->guardProjectResources($request, $project->project);

        if ($resourceError !== null) {
            return $resourceError;
        }

        $selection = $this->selector->select($project->project, $request->path, SelectionMode::Check);

        if (!$selection->isSuccessful || $selection->selection === null) {
            return $this->diagnosticResponse($request, $selection->diagnostics);
        }

        $analysis = $this->analyzer->analyze($project->project, $selection->selection->analysisSources);

        if (count($analysis->diagnostics) > CompilerAnalysisRequest::MAXIMUM_DIAGNOSTICS) {
            return CompilerAnalysisResponse::error(
                $request->requestId,
                'resource-limit-exceeded',
                'The compiler analysis produced too many diagnostics.',
                'diagnosticCount',
            );
        }

        return $this->guardResponseSize(CompilerAnalysisResponse::complete(
            $request->requestId,
            $this->diagnosticRenderer->render($analysis->diagnostics),
            $analysis,
        ));
    }

    private function diagnosticResponse(
        CompilerAnalysisRequest $request,
        DiagnosticBag $diagnostics,
    ): CompilerAnalysisResponse {
        if (count($diagnostics) > CompilerAnalysisRequest::MAXIMUM_DIAGNOSTICS) {
            return CompilerAnalysisResponse::error(
                $request->requestId,
                'resource-limit-exceeded',
                'The compiler analysis produced too many diagnostics.',
                'diagnosticCount',
            );
        }

        return $this->guardResponseSize(CompilerAnalysisResponse::complete(
            $request->requestId,
            $this->diagnosticRenderer->render($diagnostics),
        ));
    }

    private function guardProjectResources(
        CompilerAnalysisRequest $request,
        Project $project,
    ): ?CompilerAnalysisResponse {
        $paths = array_map(static fn ($source): string => $source->path, $project->sources->files);

        foreach ($project->stubs as $stub) {
            $paths[] = $stub->path;
        }

        if (count($paths) > CompilerAnalysisRequest::MAXIMUM_SOURCE_FILES) {
            return CompilerAnalysisResponse::error(
                $request->requestId,
                'resource-limit-exceeded',
                'The browser analysis project contains too many source files.',
                'sourceCount',
            );
        }

        $bytes = 0;

        foreach ($paths as $path) {
            $size = filesize($path);

            if ($size !== false) {
                $bytes += $size;
            }

            if ($bytes > CompilerAnalysisRequest::MAXIMUM_SOURCE_BYTES) {
                return CompilerAnalysisResponse::error(
                    $request->requestId,
                    'resource-limit-exceeded',
                    'The browser analysis project source payload is too large.',
                    'sourceBytes',
                );
            }
        }

        return null;
    }

    private function guardResponseSize(CompilerAnalysisResponse $response): CompilerAnalysisResponse
    {
        if (strlen(ProtocolJson::encodeCanonical($response->toArray())) <= CompilerAnalysisRequest::MAXIMUM_RESPONSE_BYTES) {
            return $response;
        }

        return CompilerAnalysisResponse::error(
            $response->requestId,
            'resource-limit-exceeded',
            'The compiler analysis response is too large.',
            'responseBytes',
        );
    }
}
