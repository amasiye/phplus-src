#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageGenerator;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', [
    'php-src:',
    'target:',
    'expected-ref:',
    'expected-commit:',
    'output::',
]);

foreach (['php-src', 'target', 'expected-ref', 'expected-commit'] as $required) {
    if (!isset($options[$required]) || !is_string($options[$required]) || $options[$required] === '') {
        fwrite(STDERR, sprintf("Missing required --%s option.\n", $required));
        exit(2);
    }
}

$output = $options['output'] ?? dirname(__DIR__) . '/resources/php-signatures/' . $options['target'];

if (!is_string($output) || $output === '') {
    fwrite(STDERR, "The --output option must name a directory.\n");
    exit(2);
}

try {
    $manifest = (new PhpSignaturePackageGenerator())->generate(
        $options['php-src'],
        $output,
        $options['target'],
        $options['expected-ref'],
        $options['expected-commit'],
    );
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Generated PHP %s signatures from %s at %s (%d inputs, %d outputs).\n",
    $manifest['targetPhpVersion'],
    $manifest['upstream']['tag'],
    $manifest['upstream']['commit'],
    count($manifest['inputs']),
    count($manifest['outputs']),
));
