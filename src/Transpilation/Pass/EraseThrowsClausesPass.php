<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Pass;

use Atatusoft\Ppphp\Frontend\Ast\ThrowsClause;
use Atatusoft\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Atatusoft\Ppphp\Transpilation\PhpDocEmitter;
use Atatusoft\Ppphp\Transpilation\TranspilationContext;

final readonly class EraseThrowsClausesPass implements TranspilationPass
{
    public function __construct(private PhpDocEmitter $phpDoc = new PhpDocEmitter()) {}

    public function execute(TranspilationContext $context): void
    {
        foreach ($context->parsedFile->extensionSyntax->throwsClauses as $clause) {
            $contract = $context->semanticModel->errorContracts->find(
                $context->parsedFile->sourceFile,
                $clause,
            );

            if ($contract === null) {
                throw new \LogicException(sprintf(
                    'The throws clause owned by %s has no validated semantic contract.',
                    $clause->ownerNameSpan->text,
                ));
            }

            $this->phpDoc->emit($context, $clause, $contract);
            $context->replace($clause->span, $this->resolveTrivia($clause, $context));
        }
    }

    private function resolveTrivia(
        ThrowsClause $clause,
        TranspilationContext $context,
    ): string {
        $trivia = '';

        foreach ($context->parsedFile->tokens->tokens as $token) {
            if (
                $token->end <= $clause->span->start->offset
                || $token->start >= $clause->span->end->offset
                || !$token->isTrivia
            ) {
                continue;
            }

            $overlapStart = max($clause->span->start->offset, $token->start);
            $overlapEnd = min($clause->span->end->offset, $token->end);
            $trivia .= substr(
                $token->text,
                $overlapStart - $token->start,
                $overlapEnd - $overlapStart,
            );
        }

        return $trivia;
    }
}
