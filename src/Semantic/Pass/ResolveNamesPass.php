<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Pass;

use Amasiye\Ppphp\Semantic\ProjectSemanticContext;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final class ResolveNamesPass
{
    public function execute(ProjectSemanticContext $context): void
    {
        foreach ($context->parseResult->parsedFiles as $parsedFile) {
            $names = new NameContext(new Collecting());
            $names->startNamespace();
            $this->resolveStatements($parsedFile->statements, $names, $context);
        }
    }

    /** @param list<Stmt> $statements */
    private function resolveStatements(array $statements, NameContext $names, ProjectSemanticContext $context): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $nested = new NameContext(new Collecting());
                $nested->startNamespace($statement->name);
                $this->resolveStatements(array_values($statement->stmts), $nested, $context);
                continue;
            }

            if ($statement instanceof Stmt\Use_) {
                foreach ($statement->uses as $use) {
                    $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $statement->type : $use->type;
                    $names->addAlias($use->name, $use->getAlias()->toString(), $type, $use->getAttributes());
                }
                continue;
            }

            if ($statement instanceof Stmt\GroupUse) {
                foreach ($statement->uses as $use) {
                    $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $statement->type : $use->type;
                    $name = Name::concat($statement->prefix, $use->name);

                    if ($name !== null) {
                        $names->addAlias($name, $use->getAlias()->toString(), $type, $use->getAttributes());
                    }
                }
                continue;
            }

            $this->resolveNode($statement, $names, $context, Stmt\Use_::TYPE_NORMAL);
        }
    }

    /** @param Stmt\Use_::TYPE_* $type */
    private function resolveNode(Node $node, NameContext $names, ProjectSemanticContext $context, int $type): void
    {
        if ($node instanceof Name) {
            $resolved = $names->getResolvedName($node, $type);
            $context->resolvedNames->record($node, ($resolved ?? $node)->toString());

            return;
        }

        if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->resolveNode($node->name, $names, $context, Stmt\Use_::TYPE_FUNCTION);
        } elseif ($node instanceof Expr\ConstFetch) {
            $this->resolveNode($node->name, $names, $context, Stmt\Use_::TYPE_CONSTANT);
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};

            if ($value instanceof Node) {
                if (($node instanceof Expr\FuncCall && $subNodeName === 'name') || ($node instanceof Expr\ConstFetch && $subNodeName === 'name')) {
                    continue;
                }

                $this->resolveNode($value, $names, $context, Stmt\Use_::TYPE_NORMAL);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->resolveNode($child, $names, $context, Stmt\Use_::TYPE_NORMAL);
                    }
                }
            }
        }
    }
}
