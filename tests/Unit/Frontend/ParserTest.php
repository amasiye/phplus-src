<?php

declare(strict_types=1);

use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Frontend\Enumerations\ParseMode;
use Amasiye\Phplus\Frontend\ParseResult;
use Amasiye\Phplus\Frontend\PhplusParser;
use Amasiye\Phplus\Frontend\PhpParserAdapter;
use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Source\SourceFile;
use PhpParser\Node\Stmt\Function_;

function parserSource(string $contents, string $name = 'Example.phplus'): SourceFile
{
    return new SourceFile(
        '/project/src/' . $name,
        'src/' . $name,
        FileKind::Phplus,
        $contents,
    );
}

test('the ordinary frontend retains an empty PHP program and its tokens', function (): void {
    $source = parserSource('<?php');
    $result = (new PhplusParser())->parse($source);
    $parsedFile = $result->parsedFile();

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->hasErrors())->toBeFalse()
        ->and($parsedFile)->not->toBeNull()
        ->and($parsedFile?->sourceFile)->toBe($source)
        ->and($parsedFile?->mode)->toBe(ParseMode::Phplus)
        ->and($parsedFile?->statements())->toBe([])
        ->and($parsedFile?->tokens())->not->toBeEmpty();
});

test('the ordinary frontend retains AST comments tokens and source positions', function (): void {
    $contents = "<?php\n\n// retained comment\nfunction answer(): int\n{\n    return 42;\n}\n";
    $result = (new PhplusParser())->parse(parserSource($contents));
    $parsedFile = $result->parsedFile();
    $statement = $parsedFile?->statements()[0] ?? null;

    expect($result->isSuccessful())->toBeTrue()
        ->and($statement)->toBeInstanceOf(Function_::class)
        ->and($statement?->getComments()[0]->getText())->toBe('// retained comment')
        ->and($statement?->getStartLine())->toBe(4)
        ->and($statement?->getEndLine())->toBe(7)
        ->and($statement?->getStartFilePos())->toBe(strpos($contents, 'function'))
        ->and($statement?->getEndFilePos())->toBe(strrpos($contents, '}'))
        ->and($statement?->getStartTokenPos())->toBeGreaterThanOrEqual(0)
        ->and($statement?->getEndTokenPos())->toBeGreaterThan($statement?->getStartTokenPos() ?? -1)
        ->and($parsedFile?->tokens())->not->toBeEmpty();
});

test('the adapter accepts the configured PHP 8.4 grammar', function (): void {
    $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Parsing/Valid/ModernPhp84.phplus');
    $result = (new PhpParserAdapter('8.4'))->parse(parserSource($contents), ParseMode::Php);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->parsedFile()?->mode)->toBe(ParseMode::Php);
});

test('the adapter rejects unsupported target versions', function (string $version): void {
    expect(fn (): PhpParserAdapter => new PhpParserAdapter($version))
        ->toThrow(InvalidArgumentException::class, 'supports only PHP 8.4');
})->with(['8.3', '8.5']);

test('the parser collects recoverable errors and never reports them as success', function (): void {
    $source = parserSource('<?php function first() { $a = ; } function second() { $b = ; }');
    $result = (new PhplusParser())->parse($source);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->hasErrors())->toBeTrue()
        ->and(count($result->diagnostics()->errors()))->toBeGreaterThanOrEqual(2);
});

test('the invalid parsing corpus produces syntax diagnostics', function (string $fixture): void {
    $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Parsing/Invalid/' . $fixture);
    $result = (new PhplusParser())->parse(parserSource($contents, $fixture));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->hasErrors())->toBeTrue()
        ->and($result->diagnostics()->errors()[0]->code)->toBe(DiagnosticCode::InvalidPhpSyntax);
})->with(['MissingSemicolon.phplus', 'UnclosedBlock.phplus', 'ExtensionSyntax.phplus']);

test('an unrecoverable parser failure may omit the parsed file', function (): void {
    $result = (new PhplusParser())->parse(parserSource('<?php function broken('));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->hasErrors())->toBeTrue()
        ->and($result->parsedFile())->toBeNull();
});

test('parse result invariants reject an empty failure', function (): void {
    expect(fn (): ParseResult => new ParseResult(null, new DiagnosticBag()))
        ->toThrow(InvalidArgumentException::class);
});

test('a recoverable parsed file with errors is not successful', function (): void {
    $successful = (new PhplusParser())->parse(parserSource('<?php echo 1;'));
    $parsedFile = $successful->parsedFile();
    $diagnostics = new DiagnosticBag();
    $diagnostics->add(new Diagnostic(
        DiagnosticCode::InvalidPhpSyntax,
        Severity::Error,
        'Invalid PHP Syntax',
        'Synthetic parser failure.',
    ));

    expect($parsedFile)->not->toBeNull();

    if ($parsedFile === null) {
        return;
    }

    $result = new ParseResult($parsedFile, $diagnostics);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->parsedFile())->toBe($parsedFile)
        ->and($result->hasErrors())->toBeTrue();
});
