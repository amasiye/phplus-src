<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cli;

use Amasiye\Ppphp\Cli\Enumerations\OutputFormat;
use Amasiye\Ppphp\Diagnostics\ConsoleRenderer;
use Amasiye\Ppphp\Diagnostics\ConsoleRenderOptions;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\JsonRenderer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

final readonly class DiagnosticOutputWriter
{
    public function __construct(
        private ConsoleRenderer $consoleRenderer,
        private JsonRenderer $jsonRenderer,
    ) {}

    public function write(
        DiagnosticBag $diagnostics,
        OutputFormat $format,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $includeDebug = $input->hasParameterOption('--debug', true);

        if ($format === OutputFormat::Json) {
            $output->write($this->jsonRenderer->render($diagnostics, $includeDebug));

            return;
        }

        $diagnosticOutput = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
        $diagnosticOutput->write($this->consoleRenderer->render(
            $diagnostics,
            new ConsoleRenderOptions(
                includeDebug: $includeDebug,
                decorated: $this->resolveDecoration($input, $diagnosticOutput),
                terminalWidth: (new Terminal())->getWidth(),
            ),
        ));
    }

    private function resolveDecoration(InputInterface $input, OutputInterface $output): bool
    {
        if ($input->hasParameterOption('--no-ansi', true)) {
            return false;
        }

        if ($input->hasParameterOption('--ansi', true)) {
            return true;
        }

        $noColor = getenv('NO_COLOR');

        if (is_string($noColor) && $noColor !== '') {
            return false;
        }

        if (getenv('TERM') === 'dumb') {
            return false;
        }

        return $output->isDecorated();
    }
}
