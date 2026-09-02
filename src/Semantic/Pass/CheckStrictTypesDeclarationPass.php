<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Pass;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Semantic\NodeSpanResolver;
use Atatusoft\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Atatusoft\Ppphp\Semantic\SemanticContext;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Declare_;

final readonly class CheckStrictTypesDeclarationPass implements SemanticPass
{
    public function __construct(private NodeSpanResolver $spans = new NodeSpanResolver()) {}

    public function execute(SemanticContext $context): void
    {
        foreach ($context->parsedFile->statements as $statement) {
            if (!$statement instanceof Declare_) {
                continue;
            }

            foreach ($statement->declares as $declaration) {
                if (strcasecmp($declaration->key->toString(), 'strict_types') !== 0) {
                    continue;
                }

                if ($declaration->value instanceof Int_ && $declaration->value->value === 1) {
                    continue;
                }

                $span = $this->spans->resolve($context->parsedFile, $declaration);
                $message = '++PHP requires strict_types=1 and does not permit an explicit strict_types=0 declaration.';
                $context->model->diagnostics->add(new Diagnostic(
                    DiagnosticCode::StrictTypesCannotBeDisabled,
                    $message,
                    new DiagnosticLabel($span, $message),
                    help: 'Remove the declaration or change it to declare(strict_types=1).',
                ));
            }
        }
    }
}
