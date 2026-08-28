<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Token;

use Amasiye\Phplus\Frontend\Token\Enumerations\TokenKind;
use Amasiye\Phplus\Source\SourceFile;

final readonly class Lexer
{
    public function tokenize(SourceFile $sourceFile): TokenStream
    {
        $tokens = [];
        $offset = 0;

        foreach (\PhpToken::tokenize($sourceFile->contents) as $nativeToken) {
            $end = $offset + strlen($nativeToken->text);
            $kind = $this->resolveKind($nativeToken);
            $isTrivia = in_array($nativeToken->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
            $tokens[] = new Token(
                $kind,
                $nativeToken->id,
                $nativeToken->text,
                $offset,
                $end,
                $sourceFile->createSpan($offset, $end),
                $isTrivia,
            );
            $offset = $end;
        }

        if ($offset !== $sourceFile->length) {
            throw new \LogicException('The tokenizer did not cover the complete source file.');
        }

        return new TokenStream($tokens);
    }

    private function resolveKind(\PhpToken $token): TokenKind
    {
        if ($token->id < 256) {
            return str_contains('(){}[],;:', $token->text)
                ? TokenKind::Punctuation
                : TokenKind::Operator;
        }

        if (in_array($token->id, [T_WHITESPACE], true)) {
            return TokenKind::Whitespace;
        }

        if (in_array($token->id, [T_COMMENT, T_DOC_COMMENT], true)) {
            return TokenKind::Comment;
        }

        if (in_array($token->id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            return TokenKind::String;
        }

        if (in_array($token->id, [T_START_HEREDOC, T_END_HEREDOC], true)) {
            return TokenKind::Heredoc;
        }

        if ($token->id === T_OPEN_TAG || $token->id === T_OPEN_TAG_WITH_ECHO) {
            return TokenKind::OpenTag;
        }

        if ($token->id === T_CLOSE_TAG) {
            return TokenKind::CloseTag;
        }

        if ($token->id === T_INLINE_HTML) {
            return TokenKind::InlineHtml;
        }

        if ($token->id === T_VARIABLE) {
            return TokenKind::Variable;
        }

        if (in_array($token->id, [T_LNUMBER, T_DNUMBER, T_NUM_STRING], true)) {
            return TokenKind::Number;
        }

        if (in_array($token->id, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true)) {
            return TokenKind::Identifier;
        }

        if ($token->isIgnorable()) {
            return TokenKind::Other;
        }

        $name = token_name($token->id);

        return str_starts_with($name, 'T_') ? TokenKind::Keyword : TokenKind::Other;
    }
}
