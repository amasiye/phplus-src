<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer\Index;

use Amasiye\Ppphp\Support\CanonicalJson;

final class DeclarationCompatibilityIdentity
{
    public static function calculate(): string
    {
        return 'sha256:' . hash('sha256', CanonicalJson::encode([
            'declarationFormatVersion' => DependencyDeclarationIndexWriter::DECLARATION_FORMAT_VERSION,
            'dependencyIndexFormatVersion' => DependencyDeclarationIndexWriter::FORMAT_VERSION,
            'phpDocParserContract' => 2,
            'phpParserContract' => 5,
            'portableSourceContract' => 'body-free-php-8.4-declarations',
        ]));
    }
}
