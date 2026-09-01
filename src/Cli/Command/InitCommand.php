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
use Amasiye\Ppphp\Diagnostics\JsonRenderer;
use Amasiye\Ppphp\Support\Path;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class InitCommand extends ProjectCommand
{
    public function __construct(
        ProjectConfigLoader $configLoader,
        ConsoleRenderer $consoleRenderer,
        JsonRenderer $jsonRenderer,
        private readonly string $templatePath,
    ) {
        parent::__construct('init', $configLoader, $consoleRenderer, $jsonRenderer);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Initialize a ++PHP project configuration.')
            ->setHelp('Creates ppphp.json in the project root. Success is written to standard output; diagnostics use standard error in console mode or one standard-output document in JSON mode.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Replace an existing ppphp.json.',
            );
        $this->addProjectOptions(false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->resolveOutputFormat($input, $output);

        if ($format === null) {
            return ExitCode::InvalidProject->value;
        }

        $projectRoot = $this->resolveWorkingDirectory($input);
        $diagnostics = new DiagnosticBag();

        if (!file_exists($projectRoot)) {
            $diagnostics->add($this->createErrorDiagnostic(
                DiagnosticCode::ProjectPathDoesNotExist,
                sprintf('The project path "%s" does not exist.', basename($projectRoot)),
            ));
        } elseif (!is_dir($projectRoot)) {
            $diagnostics->add($this->createErrorDiagnostic(
                DiagnosticCode::ProjectPathNotDirectory,
                sprintf('The project path "%s" is not a directory.', basename($projectRoot)),
            ));
        }

        if ($diagnostics->hasErrors) {
            $this->renderDiagnostics($diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $realProjectRoot = realpath($projectRoot);
        $projectRoot = $realProjectRoot === false ? $projectRoot : Path::normalize($realProjectRoot);
        $configurationPath = Path::join($projectRoot, 'ppphp.json');

        if (is_link($configurationPath)) {
            $diagnostics->add($this->createErrorDiagnostic(
                DiagnosticCode::UnsafeProjectPath,
                'The project configuration path cannot be a symbolic link.',
            ));
            $this->renderDiagnostics($diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        if (file_exists($configurationPath) && $input->getOption('force') !== true) {
            $diagnostics->add($this->createErrorDiagnostic(
                DiagnosticCode::ProjectConfigurationAlreadyExists,
                'A ppphp.json file already exists in the project root.',
                'Use --force to replace the existing project configuration.',
            ));
            $this->renderDiagnostics($diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        $template = file_get_contents($this->templatePath);

        if ($template === false) {
            throw new \RuntimeException('The maintained project configuration template is not readable.');
        }

        /** @var array{output: string, cache: string, stubs?: list<string>} $templateValues */
        $templateValues = json_decode($template, true, 512, JSON_THROW_ON_ERROR);
        $directories = [
            $templateValues['output'],
            $templateValues['cache'],
            ...($templateValues['stubs'] ?? []),
        ];
        $directoryPaths = [];

        foreach ($directories as $directory) {
            $directoryPath = Path::resolveAbsolute($directory, $projectRoot);

            if (
                !Path::contains($projectRoot, $directoryPath)
                || is_link($directoryPath)
                || Path::hasSymlinkAncestor($directoryPath, $projectRoot)
            ) {
                $diagnostics->add($this->createErrorDiagnostic(
                    DiagnosticCode::UnsafeProjectPath,
                    sprintf('The initialized directory "%s" is not a safe project path.', $directory),
                ));
                continue;
            }

            if (file_exists($directoryPath) && !is_dir($directoryPath)) {
                $diagnostics->add($this->createErrorDiagnostic(
                    DiagnosticCode::ProjectInitializationFailed,
                    sprintf('The path "%s" exists and is not a directory.', $directory),
                ));
                continue;
            }

            $directoryPaths[$directory] = $directoryPath;
        }

        if ($diagnostics->hasErrors) {
            $this->renderDiagnostics($diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        foreach ($directoryPaths as $directory => $directoryPath) {
            if (!file_exists($directoryPath) && !@mkdir($directoryPath, 0777, true) && !is_dir($directoryPath)) {
                $diagnostics->add($this->createErrorDiagnostic(
                    DiagnosticCode::ProjectInitializationFailed,
                    sprintf('The directory "%s" could not be created.', $directory),
                ));
            }
        }

        if (!$diagnostics->hasErrors && @file_put_contents($configurationPath, $template, LOCK_EX) === false) {
            $diagnostics->add($this->createErrorDiagnostic(
                DiagnosticCode::ProjectInitializationFailed,
                'The ppphp.json file could not be written.',
            ));
        }

        if ($diagnostics->hasErrors) {
            $this->renderDiagnostics($diagnostics, $format, $input, $output);

            return ExitCode::InvalidProject->value;
        }

        if ($format === OutputFormat::Json) {
            $this->renderDiagnostics($diagnostics, $format, $input, $output);
        } else {
            $output->writeln('Created ppphp.json.');
        }

        return ExitCode::Success->value;
    }

    private function createErrorDiagnostic(
        DiagnosticCode $code,
        string $message,
        ?string $help = null,
    ): Diagnostic {
        return new Diagnostic($code, $message, help: $help);
    }
}
