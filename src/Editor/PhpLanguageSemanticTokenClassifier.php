<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Editor;

use Amasiye\Ppphp\Frontend\Token\Enumerations\TokenKind;
use Amasiye\Ppphp\Frontend\Token\TokenStream;

/**
 * Projects PHP's complete tokenizer-owned language layer into editor roles.
 *
 * This derives keywords from PhpToken rather than duplicating a
 * version-sensitive word list. A new keyword supported by the compiler's PHP
 * runtime is therefore highlighted without an editor-specific patch.
 */
final readonly class PhpLanguageSemanticTokenClassifier
{
    /** @var list<int> */
    private const array MAGIC_CONSTANTS = [
        T_CLASS_C,
        T_DIR,
        T_FILE,
        T_FUNC_C,
        T_LINE,
        T_METHOD_C,
        T_NS_C,
        T_TRAIT_C,
    ];

    /** @return list<EditorSemanticToken> */
    public function classify(TokenStream $tokens): array
    {
        $result = [];

        foreach ($tokens as $token) {
            if (in_array($token->lexicalId, self::MAGIC_CONSTANTS, true)) {
                $result[] = new EditorSemanticToken(
                    'enumMember',
                    $token->span,
                    ['defaultLibrary'],
                );

                continue;
            }

            if ($token->kind !== TokenKind::Keyword || $token->lexicalId === T_STRING_VARNAME) {
                continue;
            }

            preg_match_all(
                '/[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/',
                $token->text,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            foreach ($matches[0] as [$text, $offset]) {
                $start = $token->start + $offset;
                $result[] = new EditorSemanticToken(
                    'keyword',
                    $token->span->sourceFile->createSpan($start, $start + strlen($text)),
                );
            }
        }

        return $result;
    }
}
