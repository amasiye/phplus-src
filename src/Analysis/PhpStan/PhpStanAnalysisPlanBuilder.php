<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\PhpStan;

use Amasiye\Ppphp\Analysis\AnalysisProject;
use Amasiye\Ppphp\Analysis\PhpStan\Exceptions\PhpStanExecutionException;
use Amasiye\Ppphp\Support\Path;

final readonly class PhpStanAnalysisPlanBuilder
{
    private string $compilerRoot;

    public function __construct(?string $compilerRoot = null)
    {
        $this->compilerRoot = Path::normalize($compilerRoot ?? dirname(__DIR__, 3));
    }

    public function executablePath(): string
    {
        return Path::join($this->compilerRoot, 'vendor/phpstan/phpstan/phpstan');
    }

    public function build(
        AnalysisProject $project,
        bool $debug = false,
        ?string $phpExecutable = null,
    ): PhpStanAnalysisPlan
    {
        $executable = $this->executablePath();

        if (!is_file($executable)) {
            throw new PhpStanExecutionException('The compiler-pinned static-analysis backend is not installed.');
        }

        $configuration = (new PhpStanConfigBuilder($this->compilerRoot))->build($project);
        $command = [
            $phpExecutable ?? PHP_BINARY,
            $executable,
            'analyse',
            '--configuration=' . $configuration,
            '--error-format=json',
            '--no-progress',
            '--memory-limit=1G',
        ];

        if ($debug) {
            $command[] = '--debug';
        }

        return new PhpStanAnalysisPlan(
            $command,
            $project->workspaceRoot,
            $configuration,
            Path::join($project->workspaceRoot, 'result.json'),
        );
    }
}
