<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Frontend\Ast\ExtensionSyntaxIndex;
use Amasiye\Ppphp\Frontend\Enumerations\ParseMode;
use Amasiye\Ppphp\Frontend\Normalization\NormalizationPlan;
use Amasiye\Ppphp\Frontend\Normalization\NormalizedSource;
use Amasiye\Ppphp\Frontend\Token\Lexer;
use Amasiye\Ppphp\Frontend\Token\TokenStream;
use Amasiye\Ppphp\Source\SourceFile;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Parser as NativeParser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

final readonly class PhpParserAdapter
{
    private NativeParser $parser;

    public function __construct(
        string $targetPhpVersion = '8.4',
        private PhpParserDiagnosticMapper $diagnosticMapper = new PhpParserDiagnosticMapper(),
    ) {
        if ($targetPhpVersion !== '8.4') {
            throw new \InvalidArgumentException('The ordinary PHP frontend currently supports only PHP 8.4.');
        }

        $this->parser = (new ParserFactory())->createForVersion(PhpVersion::fromString($targetPhpVersion));
    }

    public function parse(
        SourceFile $sourceFile,
        ParseMode $mode,
        ?TokenStream $tokens = null,
        ?ExtensionSyntaxIndex $extensionSyntax = null,
        ?NormalizationPlan $normalizationPlan = null,
        ?NormalizedSource $normalizedSource = null,
    ): ParseResult {
        $tokens ??= (new Lexer())->tokenize($sourceFile);
        $extensionSyntax ??= ExtensionSyntaxIndex::createEmpty();
        $normalizationPlan ??= new NormalizationPlan($sourceFile);
        $normalizedSource ??= $normalizationPlan->normalize();
        $errorHandler = new Collecting();
        $diagnostics = new DiagnosticBag();

        try {
            $statements = $this->parser->parse($normalizedSource->contents, $errorHandler);
        } catch (\PhpParser\Error $error) {
            $statements = null;
            $errorHandler->handleError($error);
        }

        foreach ($errorHandler->getErrors() as $error) {
            $diagnostics->add($this->diagnosticMapper->map($error, $sourceFile, $normalizedSource->sourceMap));
        }

        $parsedFile = $statements === null
            ? null
            : new ParsedFile(
                $sourceFile,
                $mode,
                $tokens,
                $extensionSyntax,
                $normalizationPlan,
                $normalizedSource,
                $normalizedSource->sourceMap,
                array_values($statements),
                array_values($this->parser->getTokens()),
            );

        return new ParseResult($parsedFile, $diagnostics);
    }
}
