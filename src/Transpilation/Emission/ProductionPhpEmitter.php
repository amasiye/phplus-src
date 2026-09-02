<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Transpilation\Emission;

use Amasiye\Ppphp\Compiler\CompilationArtifact;
use Amasiye\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Amasiye\Ppphp\Compiler\Output\OutputPlan;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectCheckResult;
use Amasiye\Ppphp\Transpilation\GeneratedSourceMap;
use Amasiye\Ppphp\Transpilation\PhpLowerer;
use Amasiye\Ppphp\Transpilation\Pass\RelocateComposerAutoloadPass;
use Amasiye\Ppphp\Support\Path;

final readonly class ProductionPhpEmitter
{
    public function __construct(private PhpLowerer $lowerer = new PhpLowerer()) {}

    /**
     * @param array<string, CompilationArtifact> $reusedArtifacts
     * @return list<CompilationArtifact>
     */
    public function emit(
        Project $project,
        ProjectCheckResult $check,
        OutputPlan $plan,
        array $reusedArtifacts = [],
    ): array
    {
        if (!$check->isSuccessful || $check->parseResult === null || $check->semanticResult === null) {
            throw new \LogicException('Production artifacts require a successful project check.');
        }

        $artifacts = [];

        foreach ($plan as $entry) {
            $reused = $reusedArtifacts[Path::buildComparisonKey($entry->source->path)] ?? null;

            if ($reused !== null) {
                $artifacts[] = $reused;
                continue;
            }

            $sourceFile = $check->parseResult->findSourceFile($entry->source->path);

            if ($sourceFile === null) {
                throw new \LogicException('A planned output source is missing from the checked project.');
            }

            if ($entry->operation === OutputOperation::Compile) {
                $parsedFile = $check->parseResult->findParsedFile($entry->source->path);
                $semanticModel = $check->semanticResult->findModel($entry->source->path);

                if ($parsedFile === null || $semanticModel === null) {
                    throw new \LogicException('A checked ++PHP source is missing its compilation model.');
                }

                $generated = $this->lowerer->lower($parsedFile, $semanticModel, [
                    new RelocateComposerAutoloadPass($project->composer, $entry->outputPath),
                ]);
                $contents = $generated->contents;
                $sourceMap = $generated->sourceMap;
            } else {
                $contents = $sourceFile->contents;
                $sourceMap = GeneratedSourceMap::createIdentity($sourceFile);
            }

            $permissions = @fileperms($entry->source->path);
            $artifacts[] = new CompilationArtifact(
                $entry->source,
                $sourceFile,
                $entry->operation,
                $entry->outputPath,
                $entry->relativeOutputPath,
                $contents,
                $sourceMap,
                'sha256:' . hash('sha256', $sourceFile->contents),
                'sha256:' . hash('sha256', $contents),
                $permissions === false ? null : ($permissions & 0777),
            );
        }

        return $artifacts;
    }
}
