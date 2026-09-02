<?php

declare(strict_types=1);

use Amasiye\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexReader;

require dirname(__DIR__) . '/vendor/autoload.php';

$manifestPath = $argv[1] ?? dirname(__DIR__) . '/tests/Fixtures/DependencyIndex/ppphp-dependencies/manifest.json';
$result = (new DependencyDeclarationIndexReader())->read($manifestPath, '8.4');

if (!$result->isSuccessful) {
    foreach ($result->diagnostics->errors as $diagnostic) {
        fwrite(STDERR, $diagnostic->code->value . ': ' . $diagnostic->message . "\n");
    }

    exit(1);
}

fwrite(STDOUT, sprintf(
    "Verified portable dependency index: %d declaration documents, %d aliases\n",
    count($result->parsedFiles),
    count($result->classAliases),
));
