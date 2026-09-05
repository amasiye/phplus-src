<?php

declare(strict_types=1);

// Explicit executables only. Run this file once under each declared compiler host.
// Usage: php run-platform-matrix.php /absolute/php84 /absolute/php85 /absolute/composer.phar
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Composer\InstalledVersions;
use Symfony\Component\Process\Process;
use Tests\Support\FrameworkIntegrationSpike\PlatformProfile;

$argv ??= [];
$root = dirname(__DIR__, 3);
$fixtures = $root . '/tests/Fixtures/FrameworkIntegration/PlatformProbe';
$revisionProcess = new Process(['git', 'rev-parse', 'HEAD'], $root);
$revisionProcess->mustRun();
$base = [
    'compilerRevision' => trim($revisionProcess->getOutput()),
    'host' => ['executable' => PHP_BINARY, 'version' => PHP_VERSION],
    'parser' => InstalledVersions::getPrettyVersion('nikic/php-parser'),
    'compilerLock' => hash_file('sha256', $root . '/composer.lock'),
    'productionSignatures' => '8.4.23.2 (no production 8.5 package)',
    'specimenSignatureIdentity' => hash_file('sha256', __DIR__ . '/PlatformProfile.php'),
    'emissionMode' => 'ordinary-PHP fixture passthrough; NOT ++PHP lowering',
];
$failed = false;
$notRun = false;
$emit = static function (array $row) use ($base, &$failed, &$notRun): void {
    $failed = $failed || $row['result'] === 'FAIL';
    $notRun = $notRun || $row['result'] === 'NOT RUN';
    echo json_encode([...$base, ...$row], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
};
$run = static function (array $command) use ($root): array {
    $process = new Process($command, $root, timeout: 60);
    try {
        $process->run();
        return [$process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    } catch (Throwable $error) {
        return [null, '', $error->getMessage()];
    }
};
$command = [PHP_BINARY, $root . '/vendor/bin/pest', $root . '/tests/Unit/Compiler/FrameworkPlatformPrototypeTest.php', '--compact', '--colors=never'];
[$code, $output, $stderr] = $run($command);
$emit(['target' => '8.4/8.5 specimens', 'runtime' => null, 'stage' => 'capability-rejection-and-cache-tests',
    'command' => $command, 'exit' => $code, 'result' => $code === 0 ? 'PASS' : 'FAIL', 'detail' => trim($output . $stderr)]);
foreach (['8.4' => $argv[1] ?? '', '8.5' => $argv[2] ?? ''] as $version => $executable) {
    $identify = [$executable, '-n', '-r', 'echo json_encode(["version"=>PHP_VERSION,"binary"=>PHP_BINARY,"extensions"=>array_combine(get_loaded_extensions(),array_map("phpversion",get_loaded_extensions()))]);'];
    if ($executable === '' || !str_starts_with($executable, '/') || !is_executable($executable)) {
        $emit(['target' => $version, 'runtime' => null, 'command' => $identify, 'exit' => null, 'result' => 'NOT RUN', 'detail' => 'Explicit absolute executable unavailable.']);
        continue;
    }
    [$code, $output, $stderr] = $run($identify);
    $runtime = json_decode($output, true);
    if ($code !== 0 || !is_array($runtime) || !str_starts_with($runtime['version'] ?? '', $version . '.')) {
        $emit(['target' => $version, 'runtime' => $runtime, 'command' => $identify, 'exit' => $code, 'result' => 'FAIL', 'detail' => 'Executable identity mismatch: ' . $output . $stderr]);
        continue;
    }
    $emit(['target' => $version, 'runtime' => $runtime, 'command' => $identify, 'exit' => $code, 'result' => 'PASS', 'stage' => 'identify']);
    $composer = $argv[3] ?? '';
    $command = [$executable, $composer, '--working-dir=' . $fixtures . '/dependency-rejection', 'check-platform-reqs', '--lock', '--format=json'];
    if ($composer !== '' && str_starts_with($composer, '/') && is_file($composer)) {
        [$code, $output, $stderr] = $run($command);
        $requirements = json_decode($output, true);
        $statuses = is_array($requirements) ? array_column($requirements, 'status', 'name') : [];
        $pass = $code !== null && $code !== 0 && ($statuses['ext-fi0-unavailable'] ?? '') === 'missing'
            && ($statuses['php'] ?? '') === ($version === '8.4' ? 'failed' : 'success');
        $emit(['target' => $version, 'runtime' => ['binary' => $runtime['binary'], 'version' => $runtime['version']],
            'runtimeIni' => 'default (Composer); deliberately absent ext-fi0-unavailable', 'stage' => 'composer-rejection',
            'dependencyLock' => hash_file('sha256', $fixtures . '/dependency-rejection/composer.lock'),
            'command' => $command, 'exit' => $code, 'result' => $pass ? 'PASS' : 'FAIL', 'detail' => trim($output . $stderr)]);
    } else {
        $emit(['target' => $version, 'runtime' => $runtime, 'stage' => 'composer-rejection', 'command' => $command,
            'exit' => null, 'result' => 'NOT RUN', 'detail' => 'Explicit Composer PHAR path required.']);
    }
    $profile = new PlatformProfile(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $version, $version, $version, $version);
    foreach (['hooks' => "hooks\n", 'find' => "7\n", 'pipe' => "probe\n", 'first' => "first\n"] as $name => $expected) {
        $path = $fixtures . '/' . $name . '.php';
        $expectedDiagnostic = $version === '8.4' ? match ($name) {
            'pipe' => 'SPIKE_PARSE: Syntax error',
            'first' => 'SPIKE_API_REQUIRES_8_5_SIGNATURES',
            default => null,
        } : null;
        $diagnostic = null;
        try {
            $profile->validateSpecimen((string) file_get_contents($path));
        } catch (Throwable $error) {
            $diagnostic = $error->getMessage();
        }
        $emit(['target' => $version, 'runtime' => $runtime, 'fixture' => $name, 'stage' => 'parser-and-specimen-gate',
            'command' => [PHP_BINARY, __FILE__, ...array_slice($argv, 1)], 'exit' => $diagnostic === null ? 0 : 1,
            'expectedDiagnostic' => $expectedDiagnostic, 'diagnostic' => $diagnostic,
            'result' => ($expectedDiagnostic === null ? $diagnostic === null : str_contains($diagnostic ?? '', $expectedDiagnostic)) ? 'PASS' : 'FAIL']);
        foreach (['lint' => [$executable, '-n', '-l', $path], 'runtime' => [$executable, '-n', $path]] as $stage => $command) {
            [$code, $output, $stderr] = $run($command);
            $reject = $version === '8.4' && ($name === 'pipe' || ($name === 'first' && $stage === 'runtime'));
            $marker = $name === 'pipe' ? 'syntax error' : 'Call to undefined function array_first';
            $pass = $reject ? $code !== null && $code !== 0 && str_contains($output . $stderr, $marker)
                : $code === 0 && $stderr === '' && ($stage === 'lint' || $output === $expected);
            $emit(['target' => $version, 'runtime' => $runtime, 'runtimeIni' => 'none (-n)', 'fixture' => $name,
                'stage' => $stage, 'command' => $command, 'exit' => $code, 'expectedRejection' => $reject,
                'result' => $pass ? 'PASS' : 'FAIL', 'detail' => trim($output . $stderr)]);
        }
    }
    $emit(['target' => $version, 'runtime' => $runtime, 'stage' => 'complete-framework-source-free-application',
        'command' => null, 'exit' => null, 'result' => 'NOT RUN',
        'detail' => 'Separate adapter gate; native fixtures do not establish compiler/framework support.']);
}
exit($failed ? 1 : ($notRun ? 2 : 0));
