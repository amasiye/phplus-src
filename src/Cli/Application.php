<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Cli;

use Amasiye\Phplus\Cli\Command\BuildCommand;
use Amasiye\Phplus\Cli\Command\CheckCommand;
use Amasiye\Phplus\Cli\Command\CleanCommand;
use Amasiye\Phplus\Cli\Command\DumpAstCommand;
use Amasiye\Phplus\Cli\Command\InitCommand;
use Amasiye\Phplus\Cli\Enumerations\ExitCode;
use Amasiye\Phplus\Cli\Enumerations\OutputFormat;
use Amasiye\Phplus\Config\ProjectConfigLoader;
use Amasiye\Phplus\Diagnostics\ConsoleRenderer;
use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Diagnostics\JsonRenderer;
use Amasiye\Phplus\Frontend\AstDumper;
use Amasiye\Phplus\Frontend\OutputPlanner;
use Amasiye\Phplus\Frontend\PhplusParser;
use Amasiye\Phplus\Frontend\SourcePreservingPhpBuilder;
use Amasiye\Phplus\Project\ProjectCleaner;
use Amasiye\Phplus\Project\ProjectLoader;
use Amasiye\Phplus\Project\ProjectSelector;
use Amasiye\Phplus\Project\ProjectSyntaxChecker;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Application extends SymfonyApplication
{
    public const NAME = 'PHPlus';

    public const VERSION = 'development';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $configLoader = new ProjectConfigLoader();
        $consoleRenderer = new ConsoleRenderer();
        $jsonRenderer = new JsonRenderer();
        $parser = new PhplusParser();
        $projectLoader = new ProjectLoader();
        $selector = new ProjectSelector();
        $syntaxChecker = new ProjectSyntaxChecker($parser);

        $this->addCommands([
            new InitCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                dirname(__DIR__, 2) . '/phplus.json.dist',
            ),
            new CheckCommand($configLoader, $consoleRenderer, $jsonRenderer, $projectLoader, $selector, $syntaxChecker),
            new BuildCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                $projectLoader,
                $selector,
                $syntaxChecker,
                new OutputPlanner(),
                new SourcePreservingPhpBuilder(),
            ),
            new CleanCommand($configLoader, $consoleRenderer, $jsonRenderer, new ProjectCleaner()),
            new DumpAstCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                $projectLoader,
                $selector,
                $parser,
                new AstDumper(),
            ),
        ]);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (ConsoleException $exception) {
            return $this->renderFailure(
                $input,
                $output,
                new Diagnostic(
                    DiagnosticCode::InvalidInvocation,
                    Severity::Error,
                    'Invalid Invocation',
                    $exception->getMessage(),
                ),
                ExitCode::InvalidProject,
            );
        } catch (\Throwable $exception) {
            $debug = $input->hasParameterOption('--debug');

            return $this->renderFailure(
                $input,
                $output,
                new Diagnostic(
                    DiagnosticCode::InternalCompilerError,
                    Severity::Error,
                    'Internal Compiler Error',
                    $debug
                        ? 'The compiler encountered an unexpected failure.'
                        : 'The compiler encountered an unexpected failure. Run again with --debug for additional details.',
                    debug: [
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                        'trace' => $exception->getTraceAsString(),
                    ],
                ),
                ExitCode::InternalCompilerFailure,
            );
        }
    }

    private function renderFailure(
        InputInterface $input,
        OutputInterface $output,
        Diagnostic $diagnostic,
        ExitCode $exitCode,
    ): int {
        $diagnostics = new DiagnosticBag();
        $diagnostics->add($diagnostic);
        $formatValue = $input->getParameterOption('--format', OutputFormat::Console->value);
        $format = is_string($formatValue)
            ? OutputFormat::tryFrom($formatValue)
            : null;
        $renderer = $format === OutputFormat::Json
            ? new JsonRenderer()
            : new ConsoleRenderer();
        $output->write($renderer->render(
            $diagnostics,
            $input->hasParameterOption('--debug'),
        ));

        return $exitCode->value;
    }
}
