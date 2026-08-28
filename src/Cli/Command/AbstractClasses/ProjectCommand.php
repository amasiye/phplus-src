<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Cli\Command\AbstractClasses;

use Amasiye\Phplus\Cli\Enumerations\OutputFormat;
use Amasiye\Phplus\Config\ProjectConfigLoader;
use Amasiye\Phplus\Diagnostics\ConsoleRenderer;
use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Diagnostics\JsonRenderer;
use Amasiye\Phplus\Support\Path;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ProjectCommand extends Command
{
    public function __construct(
        string $name,
        protected readonly ProjectConfigLoader $configLoader,
        private readonly ConsoleRenderer $consoleRenderer,
        private readonly JsonRenderer $jsonRenderer,
    ) {
        parent::__construct($name);
    }

    protected function addProjectOptions(bool $withConfiguration = true): void
    {
        $this->addOption(
            'working-directory',
            null,
            InputOption::VALUE_REQUIRED,
            'Project root. Defaults to the current working directory.',
        );

        if ($withConfiguration) {
            $this->addOption(
                'config',
                null,
                InputOption::VALUE_REQUIRED,
                'Configuration path relative to the project root.',
            );
        }

        $this
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Diagnostic output format: console or json.',
                OutputFormat::Console->value,
            )
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'Include internal failure details.',
            );
    }

    protected function resolveWorkingDirectory(InputInterface $input): string
    {
        $currentDirectory = getcwd();

        if ($currentDirectory === false) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }

        $value = $input->getOption('working-directory');

        if (!is_string($value) || $value === '') {
            return Path::normalize($currentDirectory);
        }

        return Path::resolveAbsolute($value, Path::normalize($currentDirectory));
    }

    protected function resolveConfigurationPath(InputInterface $input): ?string
    {
        $value = $input->getOption('config');

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function resolveOutputFormat(InputInterface $input, OutputInterface $output): ?OutputFormat
    {
        $value = $input->getOption('format');
        $format = is_string($value) ? OutputFormat::tryFrom($value) : null;

        if ($format !== null) {
            return $format;
        }

        $diagnostics = new DiagnosticBag();
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::InvalidOutputFormat,
            Severity::Error,
            'Invalid Output Format',
            'The diagnostic output format must be "console" or "json".',
        ));
        $output->write($this->consoleRenderer->render($diagnostics));

        return null;
    }

    protected function renderDiagnostics(
        DiagnosticBag $diagnostics,
        OutputFormat $format,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $renderer = $format === OutputFormat::Json
            ? $this->jsonRenderer
            : $this->consoleRenderer;
        $output->write($renderer->render(
            $diagnostics,
            $input->getOption('debug') === true,
        ));
    }
}
