<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Cli\Command;

use Amasiye\Phplus\Cli\Command\AbstractClasses\ProjectCommand;
use Amasiye\Phplus\Cli\Enumerations\ExitCode;
use Amasiye\Phplus\Cli\Enumerations\OutputFormat;
use Amasiye\Phplus\Config\ProjectConfigLoader;
use Amasiye\Phplus\Diagnostics\ConsoleRenderer;
use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Diagnostics\JsonRenderer;
use Amasiye\Phplus\Frontend\AstDumper;
use Amasiye\Phplus\Frontend\Enumerations\ParseMode;
use Amasiye\Phplus\Frontend\Interfaces\Parser;
use Amasiye\Phplus\Frontend\PhplusParser;
use Amasiye\Phplus\Project\Enumerations\SelectionMode;
use Amasiye\Phplus\Project\ProjectLoader;
use Amasiye\Phplus\Project\ProjectSelector;
use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Support\Path;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DumpAstCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ProjectLoader $projectLoader = new ProjectLoader(),
        private readonly ProjectSelector $selector = new ProjectSelector(),
        private readonly Parser $parser = new PhplusParser(),
        private readonly AstDumper $astDumper = new AstDumper(),
    ) {
        parent::__construct('dump:ast', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display the syntax tree for one project-owned PHP or ++PHP file.')
            ->addArgument('path', InputArgument::OPTIONAL, 'Explicit .php or .ppp source file path.');
        $this->addProjectOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->resolveOutputFormat($input, $output);

        if ($format === null) {
            return ExitCode::InvalidProject->value;
        }

        $configResult = $this->configLoader->load(
            $this->resolveWorkingDirectory($input),
            $this->resolveConfigurationPath($input),
            true,
        );

        if (!$configResult->isSuccessful || $configResult->configuration === null) {
            $this->renderDiagnostics($configResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $projectResult = $this->projectLoader->load($configResult->configuration);

        if (!$projectResult->isSuccessful || $projectResult->project === null) {
            $this->renderDiagnostics($projectResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $path = $input->getArgument('path');
        $selectionResult = $this->selector->select(
            $projectResult->project,
            is_string($path) ? $path : null,
            SelectionMode::DumpAst,
        );

        if (!$selectionResult->isSuccessful || $selectionResult->selection === null) {
            $this->renderDiagnostics($selectionResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $source = $selectionResult->selection->analysisSources->files[0] ?? null;

        if ($source === null) {
            throw new \LogicException('A successful dump:ast selection did not contain a source file.');
        }

        try {
            $sourceFile = $projectResult->project->sourceManager->load($source->path, $source->kind);
        } catch (\RuntimeException $exception) {
            $diagnostics = new DiagnosticBag();
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::SourceFileNotReadable,
                Severity::Error,
                'Source File Is Not Readable',
                sprintf('The source file "%s" could not be read.', Path::resolveRelativeTo($source->path, $projectResult->project->configuration->projectRoot)),
                debug: ['message' => $exception->getMessage()],
            ));
            $this->renderDiagnostics($diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $parseResult = $this->parser->parse(
            $sourceFile,
            $source->kind === FileKind::Ppp ? ParseMode::PlusPlusPhp : ParseMode::Php,
        );

        if ($parseResult->parsedFile === null) {
            $this->renderDiagnostics($parseResult->diagnostics, $format, $input, $output);

            return ExitCode::DiagnosticsReported->value;
        }

        $dump = $this->astDumper->dump($parseResult->parsedFile);

        if ($format === OutputFormat::Json) {
            $output->writeln(json_encode([
                'version' => 2,
                'file' => $sourceFile->displayPath,
                'ast' => $dump,
                'diagnostics' => array_map(
                    static fn (Diagnostic $diagnostic): array => [
                        'code' => $diagnostic->code->value,
                        'title' => $diagnostic->title,
                        'start' => $diagnostic->primary?->span->start->offset,
                        'end' => $diagnostic->primary?->span->end->offset,
                    ],
                    iterator_to_array($parseResult->diagnostics),
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            if ($parseResult->hasErrors) {
                $this->renderDiagnostics($parseResult->diagnostics, $format, $input, $output);
            }

            $output->writeln($dump);
        }

        return $parseResult->hasErrors
            ? ExitCode::DiagnosticsReported->value
            : ExitCode::Success->value;
    }
}
