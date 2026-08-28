<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Frontend\Enumerations\ParseMode;
use Amasiye\Phplus\Frontend\Extensions\ExtensionSyntaxParser;
use Amasiye\Phplus\Frontend\Interfaces\Parser;
use Amasiye\Phplus\Frontend\Token\Lexer;
use Amasiye\Phplus\Source\SourceFile;

final readonly class PhplusParser implements Parser
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
