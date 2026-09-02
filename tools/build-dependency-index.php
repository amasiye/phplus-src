<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader;
use Atatusoft\Ppphp\Interop\Composer\ComposerResolver;
use Atatusoft\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexReader;
use Atatusoft\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexWriter;
use Atatusoft\Ppphp\Support\Path;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['working-directory:', 'output:', 'allow-package-root::']);
$workingDirectory = $options['working-directory'] ?? null;
$output = $options['output'] ?? null;

if (!is_string($workingDirectory) || $workingDirectory === '' || !is_string($output) || $output === '') {
    fwrite(STDERR, "Usage: php tools/build-dependency-index.php --working-directory=<project> --output=<directory> [--allow-package-root=<path>]\n");
    exit(2);
}

$workingDirectory = realpath($workingDirectory);

if (!is_string($workingDirectory) || !is_dir($workingDirectory)) {
    fwrite(STDERR, "The dependency-index working directory is unavailable.\n");
    exit(2);
}

$output = Path::resolveAbsolute($output, $workingDirectory);
$allowed = $options['allow-package-root'] ?? [];
$allowed = is_string($allowed) ? [$allowed] : $allowed;

if (!is_array($allowed) || array_filter($allowed, static fn (mixed $path): bool => !is_string($path) || $path === '') !== []) {
    fwrite(STDERR, "Every --allow-package-root value must be a non-empty path.\n");
    exit(2);
}

$allowed = array_map(static fn (string $path): string => Path::resolveAbsolute($path, $workingDirectory), $allowed);
$resolution = (new ComposerResolver())->resolve($workingDirectory);

if (!$resolution->isSuccessful || $resolution->project === null) {
    foreach ($resolution->diagnostics->errors as $diagnostic) {
        fwrite(STDERR, $diagnostic->code->value . ': ' . $diagnostic->message . "\n");
    }

    exit(1);
}

if (!is_file(Path::join($resolution->project->vendorPath, 'composer/installed.json'))) {
    fwrite(STDERR, "Composer dependencies must already be installed; installed.json is absent.\n");
    exit(1);
}

$declarations = (new ComposerDependencyDeclarationLoader())->load(
    $resolution->project,
    [],
    $allowed,
    true,
);

if (!$declarations->isSuccessful) {
    foreach ($declarations->diagnostics->errors as $diagnostic) {
        fwrite(STDERR, $diagnostic->code->value . ': ' . $diagnostic->message . "\n");
    }

    exit(1);
}

$writer = new DependencyDeclarationIndexWriter();
$writer->write($resolution->project, $declarations, '8.4', $output);
$manifestPath = Path::join($output, 'manifest.json');
$verified = (new DependencyDeclarationIndexReader())->read(
    $manifestPath,
    '8.4',
    hash('sha256', file_get_contents($manifestPath) ?: ''),
);

if (!$verified->isSuccessful) {
    fwrite(STDERR, "The generated dependency index failed verification.\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Built and verified %s\n", $manifestPath));
