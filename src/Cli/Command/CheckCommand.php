<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Cli\Command;

use Amasiye\Phplus\Cli\Command\AbstractClasses\ProjectCommand;
use Amasiye\Phplus\Cli\Enumerations\ExitCode;
use Amasiye\Phplus\Config\ProjectConfigLoader;
use Amasiye\Phplus\Diagnostics\ConsoleRenderer;
use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Diagnostics\JsonRenderer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CheckCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
    ) {
        parent::__construct('check', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this->setDescription('Validate a project and check its source.');
        $this->addProjectOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->outputFormat($input, $output);

        if ($format === null) {
            return ExitCode::InvalidProject->value;
        }

        $result = $this->configLoader->load(
            $this->workingDirectory($input),
            $this->configurationPath($input),
            true,
        );

        if (!$result->isSuccessful()) {
            $this->renderDiagnostics($result->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $result->diagnostics->add(new Diagnostic(
            DiagnosticCode::CompilerFrontendNotAvailable,
            Severity::Error,
            'Compiler Frontend Is Not Available',
            'Source parsing and checking are not available in this compiler build.',
        ));
        $this->renderDiagnostics($result->diagnostics, $format, $input, $output);

        return ExitCode::DiagnosticsReported->value;
    }
}
