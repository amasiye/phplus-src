<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cli\Command;

use Amasiye\Ppphp\Cli\Command\AbstractClasses\ProjectCommand;
use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Amasiye\Ppphp\Cli\Enumerations\OutputFormat;
use Amasiye\Ppphp\Config\ProjectConfigLoader;
use Amasiye\Ppphp\Diagnostics\ConsoleRenderer;
use Amasiye\Ppphp\Diagnostics\JsonRenderer;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Project\ProjectCleaner;
use Amasiye\Ppphp\Support\Path;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CleanCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ProjectCleaner $projectCleaner,
    ) {
        parent::__construct('clean', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Remove compiler-owned output and cache directories.')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report paths without removing them.',
            );
        $this->addProjectOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->resolveOutputFormat($input, $output);

        if ($format === null) {
            return ExitCode::InvalidProject->value;
        }

        $loadResult = $this->configLoader->load(
            $this->resolveWorkingDirectory($input),
            $this->resolveConfigurationPath($input),
        );

        if (!$loadResult->isSuccessful || $loadResult->configuration === null) {
            $this->renderDiagnostics($loadResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $dryRun = $input->getOption('dry-run') === true;
        $cleanupResult = $this->projectCleaner->clean($loadResult->configuration, $dryRun);

        if (!$cleanupResult->isSuccessful) {
            $this->renderDiagnostics($cleanupResult->diagnostics, $format, $input, $output);

            foreach ($cleanupResult->diagnostics->errors as $diagnostic) {
                if (in_array($diagnostic->code, [
                    DiagnosticCode::BuildCouldNotBeStaged,
                    DiagnosticCode::BuildIsAlreadyInProgress,
                ], true)) {
                    return ExitCode::OutputValidationFailed->value;
                }
            }

            return ExitCode::InvalidProject->value;
        }

        if ($format === OutputFormat::Json) {
            $this->renderDiagnostics($cleanupResult->diagnostics, $format, $input, $output);
        } elseif ($cleanupResult->paths === []) {
            $output->writeln('Nothing to clean.');
        } else {
            foreach ($cleanupResult->paths as $path) {
                $action = $dryRun ? 'Would remove' : 'Removed';
                $output->writeln(sprintf(
                    '%s %s.',
                    $action,
                    Path::resolveRelativeTo($path, $loadResult->configuration->projectRoot),
                ));
            }
        }

        return ExitCode::Success->value;
    }
}
