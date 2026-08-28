<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Cli\Command;

use Amasiye\Phplus\Cli\Command\AbstractClasses\ProjectCommand;
use Amasiye\Phplus\Cli\Enumerations\ExitCode;
use Amasiye\Phplus\Cli\Enumerations\OutputFormat;
use Amasiye\Phplus\Config\ProjectConfigLoader;
use Amasiye\Phplus\Diagnostics\ConsoleRenderer;
use Amasiye\Phplus\Diagnostics\JsonRenderer;
use Amasiye\Phplus\Project\Enumerations\SelectionMode;
use Amasiye\Phplus\Project\ProjectLoader;
use Amasiye\Phplus\Project\ProjectSelector;
use Amasiye\Phplus\Project\ProjectSyntaxChecker;
use Amasiye\Phplus\Source\Enumerations\FileKind;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CheckCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ProjectLoader $projectLoader = new ProjectLoader(),
        private readonly ProjectSelector $selector = new ProjectSelector(),
        private readonly ProjectSyntaxChecker $syntaxChecker = new ProjectSyntaxChecker(),
    ) {
        parent::__construct('check', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Check project-owned PHP and PHPlus sources for syntax errors.')
            ->addArgument('path', InputArgument::OPTIONAL, 'Optional project-owned file or source subtree.');
        $this->addProjectOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->outputFormat($input, $output);

        if ($format === null) {
            return ExitCode::InvalidProject->value;
        }

        $configResult = $this->configLoader->load(
            $this->workingDirectory($input),
            $this->configurationPath($input),
            true,
        );

        if (!$configResult->isSuccessful() || $configResult->configuration === null) {
            $this->renderDiagnostics($configResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $projectResult = $this->projectLoader->load($configResult->configuration);

        if (!$projectResult->isSuccessful() || $projectResult->project === null) {
            $this->renderDiagnostics($projectResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $path = $input->getArgument('path');
        $selectionResult = $this->selector->select(
            $projectResult->project,
            is_string($path) ? $path : null,
            SelectionMode::Check,
        );

        if (!$selectionResult->isSuccessful() || $selectionResult->selection === null) {
            $this->renderDiagnostics($selectionResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $parseResult = $this->syntaxChecker->check(
            $projectResult->project,
            $selectionResult->selection->analysisSources,
        );
        $this->renderDiagnostics($parseResult->diagnostics, $format, $input, $output);

        if (!$parseResult->isSuccessful()) {
            return ExitCode::DiagnosticsReported->value;
        }

        if ($format === OutputFormat::Console) {
            $sources = $selectionResult->selection->analysisSources;
            $output->writeln(sprintf(
                'Checked %d Files: %d PHPlus, %d PHP.',
                count($sources),
                count($sources->ofKind(FileKind::Phplus)),
                count($sources->ofKind(FileKind::Php)),
            ));
        }

        return ExitCode::Success->value;
    }
}
