<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cli\Command;

use Amasiye\Ppphp\Analysis\Browser\BrowserAnalysisProtocol;
use Amasiye\Ppphp\Analysis\Browser\CompilerAnalysisProtocol;
use Amasiye\Ppphp\Analysis\Browser\CompilerAnalysisRequest;
use Amasiye\Ppphp\Analysis\Browser\CompilerAnalysisRequestDecoder;
use Amasiye\Ppphp\Analysis\Browser\PrepareAnalysisRequest;
use Amasiye\Ppphp\Analysis\Browser\PrepareAnalysisRequestDecoder;
use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Amasiye\Ppphp\Support\Path;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class BrowserAnalysisCommand extends Command
{
    public function __construct(
        private readonly BrowserAnalysisProtocol $protocol = new BrowserAnalysisProtocol(),
        private readonly PrepareAnalysisRequestDecoder $requestDecoder = new PrepareAnalysisRequestDecoder(),
        private readonly CompilerAnalysisProtocol $compilerProtocol = new CompilerAnalysisProtocol(),
        private readonly CompilerAnalysisRequestDecoder $compilerRequestDecoder = new CompilerAnalysisRequestDecoder(),
    ) {
        parent::__construct('browser:analysis');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Execute one internal browser analysis protocol request.')
            ->setHidden(true)
            ->addArgument('request', InputArgument::REQUIRED, 'Project-relative JSON request file.')
            ->addOption('working-directory', null, InputOption::VALUE_REQUIRED)
            ->addOption('config', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $protocolVersion = PrepareAnalysisRequest::VERSION;
        $requestId = null;

        try {
            $workingDirectory = $this->resolveWorkingDirectory($input);
            $requestPath = $input->getArgument('request');

            if (!is_string($requestPath) || $requestPath === '') {
                throw new \InvalidArgumentException('The browser analysis request path is required.');
            }

            $absoluteRequestPath = Path::resolveAbsolute($requestPath, $workingDirectory);
            $realRequestPath = realpath($absoluteRequestPath);

            if (
                $realRequestPath === false
                || !Path::contains($workingDirectory, Path::normalize($realRequestPath))
                || !is_file($realRequestPath)
            ) {
                throw new \InvalidArgumentException('The browser analysis request must be a project-contained file.');
            }

            $absoluteRequestPath = Path::normalize($realRequestPath);

            $size = filesize($absoluteRequestPath);

            if ($size === false || $size > max(
                PrepareAnalysisRequest::MAXIMUM_TRANSPORT_BYTES,
                CompilerAnalysisRequest::MAXIMUM_TRANSPORT_BYTES,
            )) {
                throw new \InvalidArgumentException('The browser analysis request is too large.');
            }

            $json = file_get_contents($absoluteRequestPath);

            if ($json === false) {
                throw new \RuntimeException('The browser analysis request could not be read.');
            }

            try {
                $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException('The browser analysis request must be valid JSON.', previous: $exception);
            }

            if (!is_array($payload) || !is_int($payload['version'] ?? null)) {
                throw new \InvalidArgumentException('The browser analysis protocol version is unsupported.');
            }

            $protocolVersion = $payload['version'];
            $requestId = is_string($payload['requestId'] ?? null) ? $payload['requestId'] : null;
            $configuration = $input->getOption('config');
            $configurationPath = is_string($configuration) && $configuration !== '' ? $configuration : null;

            if ($protocolVersion === PrepareAnalysisRequest::VERSION) {
                $response = $this->protocol->prepare(
                    $this->requestDecoder->decode($json),
                    $workingDirectory,
                    $configurationPath,
                )->toArray();
            } elseif ($protocolVersion === CompilerAnalysisRequest::VERSION) {
                $response = $this->compilerProtocol->analyze(
                    $this->compilerRequestDecoder->decode($json),
                    $workingDirectory,
                    $configurationPath,
                )->toArray();
            } else {
                throw new \InvalidArgumentException('The browser analysis protocol version is unsupported.');
            }

            $output->writeln(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return ExitCode::Success->value;
        } catch (\InvalidArgumentException $exception) {
            $version = $protocolVersion === CompilerAnalysisRequest::VERSION
                ? CompilerAnalysisRequest::VERSION
                : PrepareAnalysisRequest::VERSION;
            $output->writeln(json_encode([
                'version' => $version,
                'requestId' => $requestId,
                'action' => $version === CompilerAnalysisRequest::VERSION ? 'analyze' : 'prepare',
                'status' => $version === CompilerAnalysisRequest::VERSION ? 'error' : 'protocolError',
                'error' => ['code' => 'invalid-request', 'message' => $exception->getMessage()],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return ExitCode::InvalidProject->value;
        }
    }

    private function resolveWorkingDirectory(InputInterface $input): string
    {
        $currentDirectory = getcwd();

        if ($currentDirectory === false) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }

        $value = $input->getOption('working-directory');

        $resolved = is_string($value) && $value !== ''
            ? Path::resolveAbsolute($value, Path::normalize($currentDirectory))
            : Path::normalize($currentDirectory);
        $realPath = realpath($resolved);

        return $realPath === false ? $resolved : Path::normalize($realPath);
    }
}
