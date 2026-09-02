<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\Interfaces\Parser;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;

final readonly class ProjectSyntaxChecker
{
    public function __construct(private Parser $parser = new PpphpParser()) {}

    public function check(
        Project $project,
        SourceSet $selectedSources,
        ?SourceFile $documentOverride = null,
    ): ProjectParseResult
    {
        $diagnostics = new DiagnosticBag();
        $entries = [];

        foreach ($selectedSources as $source) {
            $entries[] = [$source->path, $source->kind];
        }

        foreach ($project->stubs as $stub) {
            $entries[] = [$stub->path, FileKind::Stub];
        }

        usort($entries, static fn (array $left, array $right): int =>
            Path::buildComparisonKey($left[0]) <=> Path::buildComparisonKey($right[0]));

        /** @var array<string, ParsedFile> $parsedFiles */
        $parsedFiles = [];
        /** @var array<string, SourceFile> $sourceFiles */
        $sourceFiles = [];

        foreach ($entries as [$path, $kind]) {
            try {
                $sourceFile = $documentOverride !== null
                    && Path::buildComparisonKey($documentOverride->path) === Path::buildComparisonKey($path)
                    ? $this->resolveDocumentOverride($documentOverride, $kind)
                    : $project->sourceManager->load($path, $kind);
            } catch (\RuntimeException|\InvalidArgumentException $exception) {
                $diagnostics->add(new Diagnostic(
                    DiagnosticCode::SourceFileNotReadable,
                    sprintf('The source file "%s" could not be read.', Path::resolveRelativeTo($path, $project->configuration->projectRoot)),
                    debug: ['message' => $exception->getMessage()],
                ));
                continue;
            }

            $key = Path::buildComparisonKey($path);
            $sourceFiles[$key] = $sourceFile;
            $parseResult = $this->parser->parse(
                $sourceFile,
                $kind === FileKind::Ppphp ? ParseMode::PlusPlusPhp : ParseMode::Php,
            );
            $diagnostics->addAll($parseResult->diagnostics);

            if ($parseResult->parsedFile !== null) {
                $parsedFiles[$key] = $parseResult->parsedFile;
            }
        }

        return new ProjectParseResult($parsedFiles, $sourceFiles, $diagnostics);
    }

    private function resolveDocumentOverride(SourceFile $documentOverride, FileKind $kind): SourceFile
    {
        if ($documentOverride->kind !== $kind) {
            throw new \InvalidArgumentException('The editor document kind must match the project source kind.');
        }

        return $documentOverride;
    }
}
