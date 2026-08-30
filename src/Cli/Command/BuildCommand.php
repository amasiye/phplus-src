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
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Diagnostics\JsonRenderer;
use Amasiye\Ppphp\Frontend\Enumerations\OutputOperation;
use Amasiye\Ppphp\Frontend\GeneratedPhpWriter;
use Amasiye\Ppphp\Frontend\OutputPlanner;
use Amasiye\Ppphp\Interop\Composer\ComposerRuntimeConfigurator;
use Amasiye\Ppphp\Project\Enumerations\SelectionMode;
use Amasiye\Ppphp\Project\ProjectLoader;
use Amasiye\Ppphp\Project\ProjectSelector;
use Amasiye\Ppphp\Project\ProjectChecker;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
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
        private readonly ProjectChecker $checker = new ProjectChecker(),
        private readonly OutputPlanner $outputPlanner = new OutputPlanner(),
        private readonly GeneratedPhpWriter $writer = new GeneratedPhpWriter(),
        private readonly PhpLowerer $lowerer = new PhpLowerer(),
        private readonly ComposerRuntimeConfigurator $composerRuntimeConfigurator = new ComposerRuntimeConfigurator(),
    ) {
        parent::__construct('build', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Check selected project sources and build a complete mixed PHP output tree.')
            ->addArgument('path', InputArgument::OPTIONAL, sprintf('Optional %s or %s file or source subtree.', FileKind::PHP_SUFFIX, FileKind::PPPHP_SUFFIX));
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

        $checkResult = $this->checker->check(
            $projectResult->project,
            $selectionResult->selection->analysisSources,
        );

        if (!$checkResult->isSuccessful || $checkResult->semanticResult === null) {
            $this->renderDiagnostics($checkResult->diagnostics, $format, $input, $output);

            return ExitCode::DiagnosticsReported->value;
        }

        $parseResult = $checkResult->parseResult;
        $semanticResult = $checkResult->semanticResult;

        $planResult = $this->outputPlanner->plan(
            $projectResult->project,
            $selectionResult->selection->outputSources,
        );

        if (!$planResult->isSuccessful || $planResult->plan === null) {
            $this->renderDiagnostics($planResult->diagnostics, $format, $input, $output);

            return ExitCode::OutputValidationFailed->value;
        }

        $diagnostics = new DiagnosticBag();
        $diagnostics->addAll($planResult->diagnostics);
        $composerPath = Path::join($projectResult->project->configuration->projectRoot, 'composer.json');

        if (is_file($composerPath) && !is_link($composerPath)) {
            $projection = $this->composerRuntimeConfigurator->project($projectResult->project->configuration);

            foreach ($projection->unprojectedMappings as $mapping) {
                $diagnostics->add(new Diagnostic(
                    DiagnosticCode::ComposerAutoloadDoesNotTargetBuildOutput,
                    Severity::Warning,
                    'Composer Autoload Does Not Target Build Output',
                    sprintf(
                        'Composer entry "%s.%s" still targets source path "%s"; its generated runtime path is "%s".',
                        $mapping->section,
                        $mapping->entry,
                        $mapping->sourcePath,
                        $mapping->expectedPath,
                    ),
                    help: 'Run ppphp composer:configure, then composer update --lock and composer dump-autoload.',
                ));
            }
        }

        if ($format === OutputFormat::Console && !$diagnostics->isEmpty) {
            $this->renderDiagnostics($diagnostics, $format, $input, $output);
            $output->writeln('');
        }

        foreach ($planResult->plan as $entry) {
            $sourceFile = $parseResult->findSourceFile($entry->source->path);

            if ($sourceFile === null) {
                throw new \LogicException('A successfully analyzed output source is missing from the project model.');
            }

            if ($entry->operation === OutputOperation::CompilePpphp) {
                $parsedFile = $parseResult->findParsedFile($entry->source->path);
                $semanticModel = $semanticResult->findModel($entry->source->path);

                if ($parsedFile === null || $semanticModel === null) {
                    throw new \LogicException('A successfully analyzed ++PHP source is missing from the compilation model.');
                }

                $generatedContents = $this->lowerer->lower($parsedFile, $semanticModel)->contents;
            } else {
                $generatedContents = $sourceFile->contents;
            }

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
                    '%s %s -> %s',
                    $entry->operation === OutputOperation::CompilePpphp ? 'Compiled' : 'Copied',
                    $sourceFile->displayPath,
                    Path::resolveRelativeTo($entry->outputPath, $projectResult->project->configuration->projectRoot),
                ));
            }
        }

        if ($format === OutputFormat::Json) {
            $this->renderDiagnostics($diagnostics, $format, $input, $output);
        } else {
            if (count($planResult->plan) > 0) {
                $output->writeln('');
            }

            $compiled = 0;
            $copied = 0;

            foreach ($planResult->plan as $entry) {
                if ($entry->operation === OutputOperation::CompilePpphp) {
                    $compiled++;
                } else {
                    $copied++;
                }
            }

            $output->writeln(sprintf('Compiled %d ++PHP %s.', $compiled, $compiled === 1 ? 'File' : 'Files'));
            $output->writeln(sprintf('Copied %d PHP %s.', $copied, $copied === 1 ? 'File' : 'Files'));
            $output->writeln(sprintf('Built %d %s.', count($planResult->plan), count($planResult->plan) === 1 ? 'File' : 'Files'));
        }

        return ExitCode::Success->value;
    }
}
