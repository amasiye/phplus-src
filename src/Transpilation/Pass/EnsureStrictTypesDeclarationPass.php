<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Pass;

use Atatusoft\Ppphp\Frontend\Token\Enumerations\TokenKind;
use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\SourceEditMapping;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Declare_;

final class EnsureStrictTypesDeclarationPass implements TranspilationPass
{
    public function execute(TranspilationContext $context): void
    {
        if ($this->hasStrictTypesDeclaration($context)) {
            return;
        }

        $openTag = null;

        foreach ($context->parsedFile->tokens as $token) {
            if ($token->kind === TokenKind::OpenTag) {
                $openTag = $token;
                break;
            }
        }

        if ($openTag === null) {
            throw new \LogicException('A parsed ++PHP file has no PHP opening tag.');
        }

        $source = $context->parsedFile->sourceFile;
        $newline = str_contains($source->contents, "\r\n") ? "\r\n" : "\n";
        $prefix = substr($source->contents, 0, $openTag->start);
        $shebangEnd = $this->resolveShebangEnd($prefix);
        $inlineHtml = substr($prefix, $shebangEnd);
        $owner = $openTag->span;

        if ($inlineHtml !== '') {
            $replacement = '<?php declare(strict_types=1); ?>';
            $span = $source->createSpan($shebangEnd, $shebangEnd);
            $context->replace($span, $replacement, [
                new SourceEditMapping(0, strlen($replacement), $owner),
            ]);

            return;
        }

        if ($shebangEnd > 0) {
            $replacement = '<?php declare(strict_types=1); ?>';
            $span = $source->createSpan($shebangEnd, $shebangEnd);
            $context->replace($span, $replacement, [
                new SourceEditMapping(0, strlen($replacement), $owner),
            ]);

            return;
        }

        $endsWithWhitespace = $openTag->text !== ''
            && ctype_space($openTag->text[strlen($openTag->text) - 1]);
        $replacement = 'declare(strict_types=1);' . ($endsWithWhitespace ? $newline : ' ');
        $span = $source->createSpan($openTag->end, $openTag->end);
        $context->replace($span, $replacement, [
            new SourceEditMapping(0, strlen($replacement), $owner),
        ]);
    }

    private function hasStrictTypesDeclaration(TranspilationContext $context): bool
    {
        foreach ($context->parsedFile->statements as $statement) {
            if (!$statement instanceof Declare_) {
                continue;
            }

            foreach ($statement->declares as $declaration) {
                if (
                    strcasecmp($declaration->key->toString(), 'strict_types') === 0
                    && $declaration->value instanceof Int_
                    && $declaration->value->value === 1
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resolveShebangEnd(string $prefix): int
    {
        if (!str_starts_with($prefix, '#!')) {
            return 0;
        }

        $lineFeed = strpos($prefix, "\n");

        return $lineFeed === false ? strlen($prefix) : $lineFeed + 1;
    }
}
