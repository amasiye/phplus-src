<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Extensions;

use Amasiye\Phplus\Frontend\Ast\GenericType;
use Amasiye\Phplus\Frontend\Ast\NodeId;
use Amasiye\Phplus\Frontend\Ast\SourceType;
use Amasiye\Phplus\Frontend\Token\Token;
use Amasiye\Phplus\Frontend\Token\TokenStream;
use Amasiye\Phplus\Source\SourceFile;

final class SourceTypeParser
{
    /** @var list<Token> */
    private array $tokens = [];

    private int $position = 0;

    private SourceFile $sourceFile;

    public function parse(
        SourceFile $sourceFile,
        TokenStream $tokenStream,
        int $startOffset,
        int $endOffset,
    ): SourceType {
        $this->sourceFile = $sourceFile;
        $this->tokens = $this->resolveVirtualTokens($tokenStream, $startOffset, $endOffset);
        $this->position = 0;

        if ($this->tokens === []) {
            throw new ExtensionSyntaxException(
                'A written type is required.',
                $sourceFile->createSpan($startOffset, $endOffset),
            );
        }

        $type = $this->parseCompositeType();

        if ($this->position !== count($this->tokens)) {
            throw new ExtensionSyntaxException('Malformed written type.', $this->tokens[$this->position]->span);
        }

        return $type;
    }

    /** @phpstan-impure */
    private function parseCompositeType(): SourceType
    {
        $start = $this->position;
        $genericReferences = [];
        $this->parsePrimaryType($genericReferences);

        while ($this->match('|') || $this->match('&')) {
            $this->position++;
            $this->parsePrimaryType($genericReferences);
        }

        $first = $this->tokens[$start];
        $last = $this->tokens[$this->position - 1];
        $span = $this->sourceFile->createSpan($first->start, $last->end);

        return new SourceType(NodeId::create('type', $span), $span, $span->text, $genericReferences);
    }

    /** @param list<GenericType> $genericReferences */
    private function parsePrimaryType(array &$genericReferences): void
    {
        if ($this->match('?')) {
            $this->position++;
        }

        if ($this->match('(')) {
            $this->position++;
            $nested = $this->parseCompositeType();
            array_push($genericReferences, ...$nested->genericReferences);
            $this->consume(')', 'A parenthesized type must end with `)`.');

            return;
        }

        $name = $this->resolveCurrent();

        if ($name === null) {
            throw new ExtensionSyntaxException(
                'A type name is required.',
                $this->sourceFile->createSpan($this->sourceFile->length, $this->sourceFile->length),
            );
        }

        if (!$this->matchesTypeName($name)) {
            $message = strtolower($name->text) === 'readonly'
                ? '`readonly` is a declaration modifier and cannot be used as a generic argument.'
                : 'A type name is required.';
            throw new ExtensionSyntaxException($message, $name->span);
        }

        $nameStart = $name->start;
        $nameEnd = $name->end;
        $nameText = $name->text;
        $this->position++;

        while ($this->match('\\')) {
            $this->position++;
            $part = $this->resolveCurrent();

            if ($part === null || !$this->matchesTypeName($part)) {
                throw new ExtensionSyntaxException('A qualified type name is incomplete.', $name->span);
            }

            $nameEnd = $part->end;
            $nameText .= '\\' . $part->text;
            $this->position++;
        }

        if (!$this->match('<')) {
            return;
        }

        $angleStart = $this->resolveCurrent();

        if ($angleStart === null) {
            throw new \LogicException('A matched generic opener must resolve to a token.');
        }

        $this->position++;
        $arguments = [];

        if ($this->match('>')) {
            throw new ExtensionSyntaxException('A generic argument list cannot be empty.', $angleStart->span);
        }

        while (true) {
            $argument = $this->parseCompositeType();
            $arguments[] = $argument;
            array_push($genericReferences, ...$argument->genericReferences);

            if ($this->match(',')) {
                $separator = $this->resolveCurrent();

                if ($separator === null) {
                    throw new \LogicException('A matched generic separator must resolve to a token.');
                }

                $this->position++;

                if ($this->match('>') || $this->resolveCurrent() === null) {
                    throw new ExtensionSyntaxException('A generic separator must be followed by a type.', $separator->span);
                }

                continue;
            }

            break;
        }

        $angleEnd = $this->consume('>', 'A generic argument list must end with `>`.');
        $nameSpan = $this->sourceFile->createSpan($nameStart, $nameEnd);
        $argumentListSpan = $this->sourceFile->createSpan($angleStart->start, $angleEnd->end);
        $span = $this->sourceFile->createSpan($nameStart, $angleEnd->end);
        $isTypedArray = strtolower($nameText) === 'array';

        if ($isTypedArray && !in_array(count($arguments), [1, 2], true)) {
            throw new ExtensionSyntaxException(
                'A typed array requires exactly one element type or a key and value type.',
                $argumentListSpan,
            );
        }

        $generic = new GenericType(
            NodeId::create($isTypedArray ? 'typed-array' : 'generic-type', $span),
            $span,
            $nameSpan,
            $argumentListSpan,
            $arguments,
            $isTypedArray,
        );
        $genericReferences[] = $generic;
    }

    private function consume(string $text, string $message): Token
    {
        $token = $this->resolveCurrent();

        if ($token === null || $token->text !== $text) {
            throw new ExtensionSyntaxException(
                $message,
                $token === null
                    ? $this->sourceFile->createSpan($this->sourceFile->length, $this->sourceFile->length)
                    : $token->span,
            );
        }

        $this->position++;

        return $token;
    }

    private function match(string $text): bool
    {
        return $this->resolveCurrent()?->text === $text;
    }

    private function resolveCurrent(): ?Token
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function matchesTypeName(Token $token): bool
    {
        if (strtolower($token->text) === 'readonly') {
            return false;
        }

        return in_array($token->lexicalId, [
            T_STRING,
            T_NAME_FULLY_QUALIFIED,
            T_NAME_QUALIFIED,
            T_NAME_RELATIVE,
            T_ARRAY,
            T_CALLABLE,
            T_STATIC,
        ], true);
    }

    /** @return list<Token> */
    private function resolveVirtualTokens(TokenStream $stream, int $start, int $end): array
    {
        $tokens = [];

        foreach ($stream->resolveSignificantTokens() as $token) {
            if ($token->text !== '>>') {
                if ($token->start < $start || $token->end > $end) {
                    continue;
                }

                $tokens[] = $token;
                continue;
            }

            for ($offset = $token->start; $offset < $token->end; $offset++) {
                if ($offset < $start || $offset + 1 > $end) {
                    continue;
                }

                $span = $this->sourceFile->createSpan($offset, $offset + 1);
                $tokens[] = new Token(
                    $token->kind,
                    ord('>'),
                    '>',
                    $offset,
                    $offset + 1,
                    $span,
                    false,
                );
            }
        }

        return $tokens;
    }
}
