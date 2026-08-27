<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Cli\Command;

use Amasiye\Phplus\Cli\Command\AbstractClasses\ProjectCommand;
use Amasiye\Phplus\Cli\Enumerations\ExitCode;
use Amasiye\Phplus\Config\ProjectConfigLoader;
use Amasiye\Phplus\Diagnostics\ConsoleRenderer;
use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Diagnostics\JsonRenderer;
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
    ) {
        parent::__construct('dump:ast', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display the syntax tree for a source file.')
            ->addArgument('file', InputArgument::REQUIRED, 'Source file path.');
        $this->addProjectOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->outputFormat($input, $output);

        if ($format === null) {
            return ExitCode::InvalidProject->value;
        }

        $projectRoot = $this->workingDirectory($input);
        $loadResult = $this->configLoader->load(
            $projectRoot,
            $this->configurationPath($input),
            true,
        );

        if (!$loadResult->isSuccessful() || $loadResult->configuration === null) {
            $this->renderDiagnostics($loadResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $diagnostics = new DiagnosticBag();
        $file = $input->getArgument('file');

        if (!is_string($file) || $file === '') {
            $diagnostics->add($this->error(
                DiagnosticCode::InvalidInvocation,
                'Invalid Invocation',
                'A project-relative source file path is required.',
            ));
            $this->renderDiagnostics($diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $filePath = Path::absolute($file, $loadResult->configuration->projectRoot);

        if (!Path::contains($loadResult->configuration->projectRoot, $filePath)) {
            $diagnostics->add($this->error(
                DiagnosticCode::FileOutsideProjectRoot,
                'File Is Outside Project Root',
                'The requested file must be inside the project root.',
            ));
        } elseif (!file_exists($filePath)) {
            $diagnostics->add($this->error(
                DiagnosticCode::InputFileDoesNotExist,
                'Input File Does Not Exist',
                sprintf('The requested file "%s" does not exist.', $file),
            ));
        } elseif (!is_file($filePath)) {
            $diagnostics->add($this->error(
                DiagnosticCode::InputPathNotFile,
                'Input Path Is Not A File',
                sprintf('The requested path "%s" is not a regular file.', $file),
            ));
        } else {
            $diagnostics->add($this->error(
                DiagnosticCode::CompilerFrontendNotAvailable,
                'Compiler Frontend Is Not Available',
                'AST generation is not available in this compiler build.',
            ));
        }

        $this->renderDiagnostics($diagnostics, $format, $input, $output);

        return $diagnostics->errors()[0]->code === DiagnosticCode::CompilerFrontendNotAvailable
            ? ExitCode::DiagnosticsReported->value
            : ExitCode::InvalidProject->value;
    }

    private function error(DiagnosticCode $code, string $title, string $message): Diagnostic
    {
        return new Diagnostic($code, Severity::Error, $title, $message);
    }
}
