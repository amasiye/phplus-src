<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Cli\Command;

use Amasiye\Phplus\Cli\Command\AbstractClasses\ProjectCommand;
use Amasiye\Phplus\Cli\Enumerations\ExitCode;
use Amasiye\Phplus\Cli\Enumerations\OutputFormat;
use Amasiye\Phplus\Config\ProjectConfigLoader;
use Amasiye\Phplus\Diagnostics\ConsoleRenderer;
use Amasiye\Phplus\Diagnostics\JsonRenderer;
use Amasiye\Phplus\Frontend\ExplicitSourceLoader;
use Amasiye\Phplus\Frontend\Interfaces\Parser;
use Amasiye\Phplus\Frontend\PhplusParser;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CheckCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ExplicitSourceLoader $sourceLoader = new ExplicitSourceLoader(),
        private readonly Parser $parser = new PhplusParser(),
    ) {
        parent::__construct('check', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Check one PHPlus source file for syntax errors.')
            ->addArgument('file', InputArgument::OPTIONAL, 'Explicit .phplus source file path.');
        $this->addProjectOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->outputFormat($input, $output);

        if ($format === null) {
            return ExitCode::InvalidProject->value;
        }

        $loadResult = $this->configLoader->load(
            $this->workingDirectory($input),
            $this->configurationPath($input),
            true,
        );

        if (!$loadResult->isSuccessful() || $loadResult->configuration === null) {
            $this->renderDiagnostics($loadResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $file = $input->getArgument('file');
        $sourceResult = $this->sourceLoader->load(
            $loadResult->configuration,
            is_string($file) ? $file : null,
        );

        if (!$sourceResult->isSuccessful() || $sourceResult->source === null) {
            $this->renderDiagnostics($sourceResult->diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $parseResult = $this->parser->parse($sourceResult->source->sourceFile);
        $this->renderDiagnostics($parseResult->diagnostics(), $format, $input, $output);

        if (!$parseResult->isSuccessful()) {
            return ExitCode::DiagnosticsReported->value;
        }

        if ($format === OutputFormat::Console) {
            $output->writeln(sprintf(
                'No Syntax Errors Found In %s',
                $sourceResult->source->sourceFile->displayPath,
            ));
        }

        return ExitCode::Success->value;
    }
}
