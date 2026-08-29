<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Transpilation\Pass;

use Amasiye\Ppphp\Frontend\Ast\TypedLocalDeclaration;
use Amasiye\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Amasiye\Ppphp\Transpilation\TranspilationContext;

final class LowerLocalDeclarationsPass implements TranspilationPass
{
    public function execute(TranspilationContext $context): void
    {
        foreach ($context->parsedFile->extensionSyntax->typedLocals as $declaration) {
            $binding = $context->semanticModel->bindings->find($declaration->id);

            if ($binding === null) {
                throw new \LogicException('A typed local cannot be lowered without its semantic binding.');
            }

            $prefix = $declaration->span->sourceFile->createSpan(
                $declaration->span->start->offset,
                $declaration->variableSpan->start->offset,
            );
            $context->replace(
                $prefix,
                sprintf(
                    '/** @var %s %s */%s',
                    $binding->type->text,
                    $binding->name,
                    $this->resolveTrivia($declaration, $context),
                ),
            );
        }
    }

    private function resolveTrivia(
        TypedLocalDeclaration $declaration,
        TranspilationContext $context,
    ): string {
        $start = $declaration->span->start->offset;
        $end = $declaration->variableSpan->start->offset;
        $trivia = '';

        foreach ($context->parsedFile->tokens->tokens as $token) {
            if ($token->end <= $start || $token->start >= $end || !$token->isTrivia) {
                continue;
            }

            $overlapStart = max($start, $token->start);
            $overlapEnd = min($end, $token->end);
            $trivia .= substr($token->text, $overlapStart - $token->start, $overlapEnd - $overlapStart);
        }

        return trim($trivia) === '' ? ' ' : $trivia;
    }
}
