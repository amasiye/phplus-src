<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Frontend\Enumerations\ParseMode;
use Amasiye\Phplus\Frontend\Interfaces\Parser;
use Amasiye\Phplus\Frontend\ParsedFile;
use Amasiye\Phplus\Frontend\PhplusParser;
use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Source\SourceFile;
use Amasiye\Phplus\Support\Path;

final readonly class ProjectSyntaxChecker
{
    public function __construct(private Parser $parser = new PhplusParser()) {}

    public function check(Project $project, SourceSet $selectedSources): ProjectParseResult
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
                $sourceFile = $project->sourceManager->load($path, $kind);
            } catch (\RuntimeException $exception) {
                $diagnostics->add(new Diagnostic(
                    DiagnosticCode::SourceFileNotReadable,
                    Severity::Error,
                    'Source File Is Not Readable',
                    sprintf('The source file "%s" could not be read.', Path::resolveRelativeTo($path, $project->configuration->projectRoot)),
                    debug: ['message' => $exception->getMessage()],
                ));
                continue;
            }

            $key = Path::buildComparisonKey($path);
            $sourceFiles[$key] = $sourceFile;
            $parseResult = $this->parser->parse(
                $sourceFile,
                $kind === FileKind::Ppp ? ParseMode::PlusPlusPhp : ParseMode::Php,
            );
            $diagnostics->addAll($parseResult->diagnostics);

            if ($parseResult->parsedFile !== null) {
                $parsedFiles[$key] = $parseResult->parsedFile;
            }
        }

        return new ProjectParseResult($parsedFiles, $sourceFiles, $diagnostics);
    }
}
