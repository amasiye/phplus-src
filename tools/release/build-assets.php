#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Versioning\ReleaseAssetBuilder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['commit:', 'output:']);
$commit = $options['commit'] ?? null;
$output = $options['output'] ?? dirname(__DIR__, 2) . '/dist/release/' . Compiler::VERSION;

if (!is_string($commit) || !is_string($output)) {
    fwrite(STDERR, "Usage: build-assets.php --commit=<40-hex-commit> [--output=<directory>]\n");
    exit(2);
}

if (!Atatusoft\Ppphp\Support\Path::isAbsolute($output)) {
    $output = Atatusoft\Ppphp\Support\Path::join((string) getcwd(), $output);
}

try {
    (new ReleaseAssetBuilder())->build($output, $commit);
    fwrite(STDOUT, sprintf("Built deterministic release assets for %s.\n", Compiler::VERSION));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release asset build failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
