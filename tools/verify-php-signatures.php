#!/usr/bin/env php
<?php

declare(strict_types=1);

use Amasiye\Ppphp\Interop\Php\Signature\PhpSignaturePackageVerifier;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['target::', 'package::']);
$target = $options['target'] ?? '8.4';
$package = $options['package'] ?? dirname(__DIR__) . '/resources/php-signatures/' . $target;

if (!is_string($target) || !is_string($package) || $target === '' || $package === '') {
    fwrite(STDERR, "The --target and --package options must be non-empty strings.\n");
    exit(2);
}

try {
    $manifest = (new PhpSignaturePackageVerifier())->verify($package, $target);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Verified PHP %s signature package %s (%d inputs, %d outputs).\n",
    $manifest['targetPhpVersion'],
    $manifest['packageVersion'],
    count($manifest['inputs']),
    count($manifest['outputs']),
));
