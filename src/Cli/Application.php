<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cli;

use Amasiye\Ppphp\Analysis\AnalysisWorkspacePreparer;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer;
use Amasiye\Ppphp\Cli\Command\BuildCommand;
use Amasiye\Ppphp\Cli\Command\CheckCommand;
use Amasiye\Ppphp\Cli\Command\CleanCommand;
use Amasiye\Ppphp\Cli\Command\ComposerConfigureCommand;
use Amasiye\Ppphp\Cli\Command\DumpAstCommand;
use Amasiye\Ppphp\Cli\Command\EditorDefinitionCommand;
use Amasiye\Ppphp\Cli\Command\InitCommand;
use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Amasiye\Ppphp\Cli\Enumerations\OutputFormat;
use Amasiye\Ppphp\Config\ProjectConfigLoader;
use Amasiye\Ppphp\Diagnostics\ConsoleRenderer;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Diagnostics\JsonRenderer;
use Amasiye\Ppphp\Frontend\AstDumper;
use Amasiye\Ppphp\Frontend\GeneratedPhpWriter;
use Amasiye\Ppphp\Frontend\OutputPlanner;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Interop\Composer\ComposerConfigurationWriter;
use Amasiye\Ppphp\Interop\Composer\ComposerRuntimeConfigurator;
use Amasiye\Ppphp\Project\ProjectCleaner;
use Amasiye\Ppphp\Project\ProjectChecker;
use Amasiye\Ppphp\Project\ProjectLoader;
use Amasiye\Ppphp\Project\ProjectSelector;
use Amasiye\Ppphp\Project\ProjectSyntaxChecker;
use Amasiye\Ppphp\Semantic\SemanticAnalyzer;
use Amasiye\Ppphp\Transpilation\PhpLowerer;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Application extends SymfonyApplication
{
    public const NAME = 'ppphp';

    public const VERSION = 'development';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $configLoader = new ProjectConfigLoader();
        $consoleRenderer = new ConsoleRenderer();
        $jsonRenderer = new JsonRenderer();
        $parser = new PpphpParser();
        $projectLoader = new ProjectLoader();
        $selector = new ProjectSelector();
        $syntaxChecker = new ProjectSyntaxChecker($parser);
        $semanticAnalyzer = new SemanticAnalyzer();
        $lowerer = new PhpLowerer();
        $composerRuntimeConfigurator = new ComposerRuntimeConfigurator();
        $checker = new ProjectChecker(
            $syntaxChecker,
            $semanticAnalyzer,
            new AnalysisWorkspacePreparer($syntaxChecker, $semanticAnalyzer, $lowerer),
            new PhpStanProjectAnalyzer(),
        );

        $this->addCommands([
            new InitCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                dirname(__DIR__, 2) . '/ppphp.json.dist',
            ),
            new CheckCommand($configLoader, $consoleRenderer, $jsonRenderer, $projectLoader, $selector, $checker),
            new ComposerConfigureCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                $composerRuntimeConfigurator,
                new ComposerConfigurationWriter(),
            ),
            new BuildCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                $projectLoader,
                $selector,
                $checker,
                new OutputPlanner(),
                new GeneratedPhpWriter(),
                $lowerer,
                $composerRuntimeConfigurator,
            ),
            new CleanCommand($configLoader, $consoleRenderer, $jsonRenderer, new ProjectCleaner()),
            new EditorDefinitionCommand(
                $configLoader,
                $consoleRenderer,
                $jsonRenderer,
                $projectLoader,
                $syntaxChecker,
                $semanticAnalyzer,
            ),
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
