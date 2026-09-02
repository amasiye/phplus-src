<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Validation\SymfonyPhpLintRunner;
use Atatusoft\Ppphp\Process\BoundedProcessResult;
use Atatusoft\Ppphp\Process\BoundedProcessRunner;
use Atatusoft\Ppphp\Process\ProcessPolicy;

test('bounded processes preserve argument boundaries and a reviewed environment', function (): void {
    putenv('PPPHP_PROCESS_SECRET=must-not-cross');
    $_SERVER['PPPHP_PROCESS_SECRET'] = 'must-not-cross';

    try {
        $result = (new BoundedProcessRunner())->run([
            PHP_BINARY,
            '-n',
            '-r',
            'echo ($argv[1] ?? ""), "|", getenv("PPPHP_PROCESS_SECRET") === false ? "absent" : "present";',
            'literal;not-a-shell',
        ], null, 2.0, 1_024, 1_024);
    } finally {
        putenv('PPPHP_PROCESS_SECRET');
        unset($_SERVER['PPPHP_PROCESS_SECRET']);
    }

    $environment = (new ProcessPolicy())->environment();

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toBe('literal;not-a-shell|absent')
        ->and($result->outputLimitExceeded)->toBeFalse()
        ->and($environment['PPPHP_PROCESS_SECRET'] ?? null)->toBeNull();
});

test('stdout stderr and execution time are bounded', function (string $script, int $stdout, int $stderr, bool $timedOut): void {
    $result = (new BoundedProcessRunner())->run(
        [PHP_BINARY, '-n', '-r', $script],
        null,
        $timedOut ? 0.05 : 2.0,
        128,
        128,
    );

    expect(strlen($result->stdout))->toBeLessThanOrEqual(128)
        ->and(strlen($result->stderr))->toBeLessThanOrEqual(128)
        ->and($result->timedOut)->toBe($timedOut)
        ->and($result->outputLimitExceeded)->toBe(!$timedOut)
        ->and(strlen($result->stdout))->toBe($stdout)
        ->and(strlen($result->stderr))->toBe($stderr);
})->with([
    'stdout' => ['fwrite(STDOUT, str_repeat("x", 4096));', 128, 0, false],
    'stderr' => ['fwrite(STDERR, str_repeat("x", 4096));', 0, 128, false],
    'timeout' => ['while (true) {}', 0, 0, true],
]);

test('PHP lint disables user configuration and uses the bounded runner', function (): void {
    $runner = new class extends BoundedProcessRunner {
        /** @var list<string> */
        public array $command = [];

        public function run(
            array $command,
            ?string $workingDirectory,
            float $timeoutSeconds,
            int $maximumStdoutBytes = ProcessPolicy::MAXIMUM_STDOUT_BYTES,
            int $maximumStderrBytes = ProcessPolicy::MAXIMUM_STDERR_BYTES,
        ): BoundedProcessResult {
            $this->command = $command;

            return new BoundedProcessResult(0, 'No syntax errors detected', '');
        }
    };
    $result = (new SymfonyPhpLintRunner($runner))->run('/project/build/One.php', 1.0);

    expect($result->isSuccessful)->toBeTrue()
        ->and($runner->command)->toBe([PHP_BINARY, '-n', '-l', '/project/build/One.php']);
});
