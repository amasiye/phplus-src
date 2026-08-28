<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Cli\Command;

use Amasiye\Phplus\Cli\Command\AbstractClasses\ProjectCommand;
use Amasiye\Phplus\Cli\Enumerations\ExitCode;
use Amasiye\Phplus\Cli\Enumerations\OutputFormat;
use Amasiye\Phplus\Config\ProjectConfigLoader;
use Amasiye\Phplus\Diagnostics\ConsoleRenderer;
use Amasiye\Phplus\Diagnostics\JsonRenderer;
use Amasiye\Phplus\Frontend\AstDumper;
use Amasiye\Phplus\Frontend\ExplicitSourceLoader;
use Amasiye\Phplus\Frontend\Interfaces\Parser;
use Amasiye\Phplus\Frontend\PhplusParser;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DumpAstCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly ExplicitSourceLoader $sourceLoader = new ExplicitSourceLoader(),
        private readonly Parser $parser = new PhplusParser(),
        private readonly AstDumper $astDumper = new AstDumper(),
    ) {
        parent::__construct('dump:ast', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display the syntax tree for a source file.')
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

        if (!$parseResult->isSuccessful() || $parseResult->parsedFile() === null) {
            $this->renderDiagnostics($parseResult->diagnostics(), $format, $input, $output);

            return ExitCode::DiagnosticsReported->value;
        }

        $dump = $this->astDumper->dump($parseResult->parsedFile());

        if ($format === OutputFormat::Json) {
            $output->writeln(json_encode([
                'version' => 1,
                'file' => $sourceResult->source->sourceFile->displayPath,
                'ast' => $dump,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $output->writeln($dump);
        }

        return ExitCode::Success->value;
    }
}
