<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Manifest;

use Atatusoft\Ppphp\Cache\CompilerBuildIdentity;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Support\Path;

final class ConfigurationFingerprint
{
    public function __construct(
        private readonly string $compilerVersion = Compiler::VERSION,
        private readonly CompilerBuildIdentity $buildIdentity = new CompilerBuildIdentity(),
    ) {}

    public function calculate(Project $project): string
    {
        $configuration = $project->configuration;
        $sourceRoots = array_map(
            static fn (string $path): string => Path::resolveRelativeTo($path, $configuration->projectRoot),
            $configuration->sourceRoots,
        );
        $excludedPaths = array_map(
            static fn (string $path): string => Path::resolveRelativeTo($path, $configuration->projectRoot),
            $configuration->excludedPaths,
        );
        sort($excludedPaths, SORT_STRING);
        $vendor = Path::makeRelative($project->composer->vendorPath, $configuration->projectRoot)
            ?? Path::resolveRelativeTo($project->composer->vendorPath, $configuration->projectRoot);
        $inputs = [
            'compilerVersion' => $this->compilerVersion,
            'compilerBuildIdentity' => $this->buildIdentity->calculate(),
            'manifestFormatVersion' => BuildManifest::FORMAT_VERSION,
            'loweringFormatVersion' => Compiler::LOWERING_FORMAT_VERSION,
            'targetPhpVersion' => $configuration->targetPhpVersion,
            'sourceRoots' => $sourceRoots,
            'excludedPaths' => $excludedPaths,
            'output' => Path::resolveRelativeTo($configuration->outputPath, $configuration->projectRoot),
            'composerVendor' => $vendor,
        ];
        $json = json_encode($inputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return 'sha256:' . hash('sha256', $json);
    }
}
