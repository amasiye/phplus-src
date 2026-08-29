<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\Enumerations\ParseMode;
use Amasiye\Ppphp\Frontend\Extensions\ExtensionSyntaxParser;
use Amasiye\Ppphp\Frontend\Interfaces\Parser;
use Amasiye\Ppphp\Frontend\Token\Lexer;
use Amasiye\Ppphp\Source\SourceFile;

final readonly class PpphpParser implements Parser
{
    public function __construct(
        private PhpParserAdapter $phpParserAdapter = new PhpParserAdapter(),
        private Lexer $lexer = new Lexer(),
        private ExtensionSyntaxParser $extensionSyntaxParser = new ExtensionSyntaxParser(),
    ) {}

    public function parse(
        SourceFile $sourceFile,
        ParseMode $mode = ParseMode::PlusPlusPhp,
    ): ParseResult {
        if ($mode === ParseMode::Php) {
            return $this->phpParserAdapter->parse($sourceFile, $mode);
        }

        $tokens = $this->lexer->tokenize($sourceFile);
        $extensionResult = $this->extensionSyntaxParser->parse($sourceFile, $tokens);

        foreach ($extensionResult->diagnostics as $diagnostic) {
            if (in_array($diagnostic->code, [
                DiagnosticCode::InvalidExtensionSyntax,
                DiagnosticCode::UnsupportedExtensionSyntax,
                DiagnosticCode::ExtensionNormalizationFailed,
            ], true)) {
                return new ParseResult(null, $extensionResult->diagnostics);
            }
        }

        $normalizedSource = $extensionResult->normalizationPlan->normalize();
        $phpResult = $this->phpParserAdapter->parse(
            $sourceFile,
            $mode,
            $tokens,
            $extensionResult->index,
            $extensionResult->normalizationPlan,
            $normalizedSource,
        );
        $diagnostics = new DiagnosticBag();
        $diagnostics->addAll($extensionResult->diagnostics);
        $diagnostics->addAll($phpResult->diagnostics);

        return new ParseResult($phpResult->parsedFile, $diagnostics);
    }
}
