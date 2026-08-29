<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cli\Command;

use Amasiye\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Amasiye\Ppphp\Cli\Enumerations\OutputFormat;
use Amasiye\Ppphp\Config\ProjectConfigLoader;
use Amasiye\Ppphp\Diagnostics\ConsoleRenderer;
use Amasiye\Ppphp\Diagnostics\JsonRenderer;
use Amasiye\Ppphp\Frontend\GeneratedPhpWriter;
use Amasiye\Ppphp\Frontend\OutputPlanner;
use Amasiye\Ppphp\Project\Enumerations\SelectionMode;
use Amasiye\Ppphp\Project\ProjectLoader;
use Amasiye\Ppphp\Project\ProjectSelector;
use Amasiye\Ppphp\Project\ProjectSyntaxChecker;
use Amasiye\Ppphp\Semantic\SemanticAnalyzer;
use Amasiye\Ppphp\Support\Path;
use Amasiye\Ppphp\Transpilation\PhpLowerer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class BuildCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ProjectLoader $projectLoader = new ProjectLoader(),
        private readonly ProjectSelector $selector = new ProjectSelector(),
        private readonly ProjectSyntaxChecker $syntaxChecker = new ProjectSyntaxChecker(),
        private readonly OutputPlanner $outputPlanner = new OutputPlanner(),
        private readonly GeneratedPhpWriter $writer = new GeneratedPhpWriter(),
        private readonly SemanticAnalyzer $semanticAnalyzer = new SemanticAnalyzer(),
        private readonly PhpLowerer $lowerer = new PhpLowerer(),
    ) {
        parent::__construct('build', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Check selected project sources and emit selected ++PHP files as PHP.')
            ->addArgument('path', InputArgument::OPTIONAL, 'Optional .ppp file or source subtree.');
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
            SelectionMode::Build,
        );

        if (!$selectionResult->isSuccessful || $selectionResult->selection === null) {
            $this->renderDiagnostics($selectionResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $parseResult = $this->syntaxChecker->check(
            $projectResult->project,
            $selectionResult->selection->analysisSources,
        );

        if (!$parseResult->isSuccessful) {
            $this->renderDiagnostics($parseResult->diagnostics, $format, $input, $output);

            return ExitCode::DiagnosticsReported->value;
        }

        $semanticResult = $this->semanticAnalyzer->analyze($parseResult);

        if (!$semanticResult->isSuccessful) {
            $this->renderDiagnostics($semanticResult->diagnostics, $format, $input, $output);

            return ExitCode::DiagnosticsReported->value;
        }

        $planResult = $this->outputPlanner->plan(
            $projectResult->project,
            $selectionResult->selection->emissionSources,
        );

        if (!$planResult->isSuccessful || $planResult->plan === null) {
            $this->renderDiagnostics($planResult->diagnostics, $format, $input, $output);

            return ExitCode::OutputValidationFailed->value;
        }

        foreach ($planResult->plan as $entry) {
            $parsedFile = $parseResult->findParsedFile($entry->source->path);
            $semanticModel = $semanticResult->findModel($entry->source->path);

            if ($parsedFile === null || $semanticModel === null) {
                throw new \LogicException('A successfully analyzed emission source is missing from the compilation model.');
            }

            $generatedContents = $this->lowerer->lower($parsedFile, $semanticModel);
            $buildResult = $this->writer->write(
                $projectResult->project->configuration,
                $generatedContents,
                $entry->outputPath,
            );

            if (!$buildResult->isSuccessful) {
                $this->renderDiagnostics($buildResult->diagnostics, $format, $input, $output);

                return ExitCode::OutputValidationFailed->value;
            }

            if ($format === OutputFormat::Console) {
                $output->writeln(sprintf(
                    'Built %s -> %s',
                    $parsedFile->sourceFile->displayPath,
                    Path::resolveRelativeTo($entry->outputPath, $projectResult->project->configuration->projectRoot),
                ));
            }
        }

        if ($format === OutputFormat::Json) {
            $this->renderDiagnostics($planResult->diagnostics, $format, $input, $output);
        } else {
            if (count($planResult->plan) > 0) {
                $output->writeln('');
            }

            $output->writeln(sprintf('Built %d ++PHP Files.', count($planResult->plan)));
        }

        return ExitCode::Success->value;
    }
}
