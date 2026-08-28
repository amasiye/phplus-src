<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Frontend\Enumerations\ParseMode;
use Amasiye\Phplus\Source\SourceFile;
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

    public function parse(SourceFile $sourceFile, ParseMode $mode): ParseResult
    {
        $errorHandler = new Collecting();
        $statements = $this->parser->parse($sourceFile->contents, $errorHandler);
        $diagnostics = new DiagnosticBag();

        foreach ($errorHandler->getErrors() as $error) {
            $diagnostics->add($this->diagnosticMapper->map($error, $sourceFile));
        }

        $parsedFile = $statements === null
            ? null
            : new ParsedFile(
                $sourceFile,
                $mode,
                array_values($statements),
                array_values($this->parser->getTokens()),
            );

        return new ParseResult($parsedFile, $diagnostics);
    }
}
