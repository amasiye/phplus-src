<?php

declare(strict_types=1);

use Amasiye\Ppphp\Editor\PhpLanguageSemanticTokenClassifier;
use Amasiye\Ppphp\Editor\EditorSemanticTokenResolver;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Frontend\Token\Lexer;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;

test('the PHP tokenizer supplies the complete reserved-word highlighting layer', function (): void {
    $reservedWords = [
        'abstract', 'and', 'array', 'as', 'break', 'callable',
        'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default',
        'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
        'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit',
        'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global',
        'goto', 'if', 'implements', 'include', 'include_once', 'instanceof',
        'insteadof', 'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or',
        'print', 'private', 'protected', 'public', 'readonly', 'require',
        'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try',
        'unset', 'use', 'var', 'while', 'xor', 'yield', 'from', '__halt_compiler',
    ];
    $source = new SourceFile(
        '/project/src/Keywords.ppphp',
        'src/Keywords.ppphp',
        FileKind::Ppphp,
        '<?php ' . implode(' ', $reservedWords),
    );
    $tokens = (new PhpLanguageSemanticTokenClassifier())->classify((new Lexer())->tokenize($source));
    $keywords = array_map(
        static fn ($token): string => $token->range->text,
        array_values(array_filter($tokens, static fn ($token): bool => $token->type === 'keyword')),
    );

    expect(array_values(array_unique($keywords)))->toBe($reservedWords);
});

test('PHP magic constants use a predefined-library semantic role', function (): void {
    $constants = [
        '__CLASS__', '__DIR__', '__FILE__', '__FUNCTION__', '__LINE__',
        '__METHOD__', '__NAMESPACE__', '__TRAIT__',
    ];
    $source = new SourceFile(
        '/project/src/Constants.ppphp',
        'src/Constants.ppphp',
        FileKind::Ppphp,
        '<?php ' . implode(' ', $constants),
    );
    $tokens = (new PhpLanguageSemanticTokenClassifier())->classify((new Lexer())->tokenize($source));

    expect(array_map(
        static fn ($token): array => [$token->range->text, $token->type, $token->modifiers],
        $tokens,
    ))->toBe(array_map(
        static fn (string $constant): array => [$constant, 'enumMember', ['defaultLibrary']],
        $constants,
    ));
});

test('all contextual PHP native types and constants receive native-library roles', function (): void {
    $contents = <<<'PPPHP'
<?php
class Base {}
class Example extends Base
{
    public function values(
        bool $bool,
        false $false,
        float $float,
        int $int,
        iterable $iterable,
        mixed $mixed,
        null $null,
        object $object,
        parent $parent,
        self $self,
        string $string,
        true $true,
    ): self {
        return $this;
    }

    public function stops(): never
    {
        throw new RuntimeException();
    }

    public function completes(): void
    {
    }

    public function constants(): array
    {
        return [true, false, null];
    }
}
PPPHP;
    $source = new SourceFile(
        '/project/src/NativeTypes.ppphp',
        'src/NativeTypes.ppphp',
        FileKind::Ppphp,
        $contents,
    );
    $parseResult = (new PpphpParser())->parse($source);
    $parsedFile = $parseResult->parsedFile;
    expect($parseResult->isSuccessful)->toBeTrue()->and($parsedFile)->not->toBeNull();

    $tokens = (new EditorSemanticTokenResolver())->resolve($parsedFile);
    $nativeTypes = array_values(array_unique(array_map(
        static fn ($token): string => $token->range->text,
        array_filter(
            $tokens,
            static fn ($token): bool => $token->type === 'type'
                && $token->modifiers === ['defaultLibrary'],
        ),
    )));
    $constants = array_map(
        static fn ($token): string => $token->range->text,
        array_filter(
            $tokens,
            static fn ($token): bool => $token->type === 'enumMember'
                && $token->modifiers === ['defaultLibrary']
                && in_array($token->range->text, ['false', 'null', 'true'], true),
        ),
    );

    expect($nativeTypes)->toBe([
        'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'null', 'object',
        'parent', 'self', 'string', 'true', 'never', 'void',
    ])->and(array_values(array_unique($constants)))->toBe(['true', 'false', 'null']);
});
