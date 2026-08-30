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
use PhpParser\Node;
use PhpParser\Node\Expr;

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

        if ($phpResult->parsedFile !== null) {
            $this->markWhenPlaceholders($phpResult->parsedFile);
        }
        $diagnostics = new DiagnosticBag();
        $diagnostics->addAll($extensionResult->diagnostics);
        $diagnostics->addAll($phpResult->diagnostics);

        return new ParseResult($phpResult->parsedFile, $diagnostics);
    }

    private function markWhenPlaceholders(ParsedFile $parsedFile): void
    {
        $expressions = [];

        foreach ($parsedFile->extensionSyntax->whenExpressions as $when) {
            if ($when->parentId === null) {
                $expressions[$when->span->start->offset] = $when;
            }
        }

        $visit = function (Node $node) use (&$visit, $expressions): void {
            if (
                $node instanceof Expr\ConstFetch
                && strtolower($node->name->toString()) === 'null'
                && isset($expressions[$node->getStartFilePos()])
            ) {
                $node->setAttribute('ppphpWhenExpressionId', $expressions[$node->getStartFilePos()]->id->value);
            }

            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->{$name};

                if ($value instanceof Node) {
                    $visit($value);
                } elseif (is_array($value)) {
                    foreach ($value as $child) {
                        if ($child instanceof Node) {
                            $visit($child);
                        }
                    }
                }
            }
        };

        foreach ($parsedFile->statements as $statement) {
            $visit($statement);
        }
    }
}
