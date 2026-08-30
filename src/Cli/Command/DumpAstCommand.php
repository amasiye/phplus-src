<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cli\Command;

use Amasiye\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Amasiye\Ppphp\Cli\Enumerations\OutputFormat;
use Amasiye\Ppphp\Config\ProjectConfigLoader;
use Amasiye\Ppphp\Diagnostics\ConsoleRenderer;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\JsonRenderer;
use Amasiye\Ppphp\Frontend\AstDumper;
use Amasiye\Ppphp\Frontend\Enumerations\ParseMode;
use Amasiye\Ppphp\Frontend\Interfaces\Parser;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Project\Enumerations\SelectionMode;
use Amasiye\Ppphp\Project\ProjectLoader;
use Amasiye\Ppphp\Project\ProjectSelector;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Support\Path;
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
        private readonly Parser $parser = new PpphpParser(),
        private readonly AstDumper $astDumper = new AstDumper(),
    ) {
        parent::__construct('dump:ast', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display the syntax tree for one project-owned PHP or ++PHP file.')
            ->setHelp('Writes one selected source tree to standard output. Parse and project diagnostics use standard error in console mode; JSON AST output remains one machine-readable standard-output document.')
            ->addArgument('path', InputArgument::OPTIONAL, sprintf('Explicit %s or %s source file path.', FileKind::PHP_SUFFIX, FileKind::PPPHP_SUFFIX));
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
                sprintf('The source file "%s" could not be read.', Path::resolveRelativeTo($source->path, $projectResult->project->configuration->projectRoot)),
                debug: ['message' => $exception->getMessage()],
            ));
            $this->renderDiagnostics($diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $parseResult = $this->parser->parse(
            $sourceFile,
            $source->kind === FileKind::Ppphp ? ParseMode::PlusPlusPhp : ParseMode::Php,
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
