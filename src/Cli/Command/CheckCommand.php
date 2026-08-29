<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cli\Command;

use Amasiye\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Amasiye\Ppphp\Cli\Enumerations\OutputFormat;
use Amasiye\Ppphp\Config\ProjectConfigLoader;
use Amasiye\Ppphp\Diagnostics\ConsoleRenderer;
use Amasiye\Ppphp\Diagnostics\JsonRenderer;
use Amasiye\Ppphp\Project\Enumerations\SelectionMode;
use Amasiye\Ppphp\Project\ProjectLoader;
use Amasiye\Ppphp\Project\ProjectSelector;
use Amasiye\Ppphp\Project\ProjectSyntaxChecker;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
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
            ->setDescription('Check project-owned PHP and ++PHP sources for syntax errors.')
            ->addArgument('path', InputArgument::OPTIONAL, 'Optional project-owned file or source subtree.');
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
            SelectionMode::Check,
        );

        if (!$selectionResult->isSuccessful || $selectionResult->selection === null) {
            $this->renderDiagnostics($selectionResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $parseResult = $this->syntaxChecker->check(
            $projectResult->project,
            $selectionResult->selection->analysisSources,
        );
        $this->renderDiagnostics($parseResult->diagnostics, $format, $input, $output);

        if (!$parseResult->isSuccessful) {
            return ExitCode::DiagnosticsReported->value;
        }

        if ($format === OutputFormat::Console) {
            $sources = $selectionResult->selection->analysisSources;
            $output->writeln(sprintf(
                'Checked %d Files: %d ++PHP, %d PHP.',
                count($sources),
                count($sources->filterByKind(FileKind::Ppp)),
                count($sources->filterByKind(FileKind::Php)),
            ));
        }

        return ExitCode::Success->value;
    }
}
