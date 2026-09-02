<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer\Index;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;

final readonly class PortableDeclarationValidator
{
    public function __construct(
        private ParserFactory $parsers = new ParserFactory(),
        private NodeFinder $nodes = new NodeFinder(),
    ) {}

    public function validateSource(string $source): void
    {
        $statements = $this->parsers->createForNewestSupportedVersion()->parse($source);

        if ($statements === null) {
            throw new \RuntimeException('A portable declaration document could not be parsed.');
        }

        $this->validateStatements(array_values($statements));
    }

    /** @param list<Stmt> $statements */
    public function validateStatements(array $statements): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->validateStatements(array_values($statement->stmts));
                continue;
            }

            if ($statement instanceof Stmt\Function_) {
                if ($statement->stmts !== []) {
                    throw new \RuntimeException('A portable function retains an implementation body.');
                }

                $this->validateParameters($statement->params);
                continue;
            }

            if ($statement instanceof Stmt\ClassLike) {
                foreach ($statement->getMethods() as $method) {
                    if ($method->stmts !== null && $method->stmts !== []) {
                        throw new \RuntimeException('A portable method retains an implementation body.');
                    }

                    $this->validateParameters($method->params);
                }

                foreach ($statement->getProperties() as $property) {
                    foreach ($property->hooks as $hook) {
                        if ($hook->body !== null && $hook->body !== []) {
                            throw new \RuntimeException('A portable property hook retains an implementation body.');
                        }

                        $this->validateParameters($hook->params);
                    }
                }

                $this->rejectAnonymousCallables($statement);
                continue;
            }

            if ($statement instanceof Stmt\Use_
                || $statement instanceof Stmt\GroupUse
                || $statement instanceof Stmt\Const_
                || $statement instanceof Stmt\Declare_) {
                $this->rejectAnonymousCallables($statement);
                continue;
            }

            throw new \RuntimeException('A portable document contains a top-level executable statement.');
        }
    }

    /** @param array<int|string, Node\Param> $parameters */
    private function validateParameters(array $parameters): void
    {
        foreach ($parameters as $parameter) {
            foreach ($parameter->hooks as $hook) {
                if ($hook->body !== null && $hook->body !== []) {
                    throw new \RuntimeException('A promoted portable property hook retains an implementation body.');
                }
            }

            if ($parameter->default !== null) {
                $this->rejectAnonymousCallables($parameter->default);
            }
        }
    }

    private function rejectAnonymousCallables(Node $node): void
    {
        $callable = $this->nodes->findFirst($node, static fn (Node $candidate): bool =>
            $candidate instanceof Node\Expr\Closure || $candidate instanceof Node\Expr\ArrowFunction);

        if ($callable !== null) {
            throw new \RuntimeException('A portable declaration contains an executable anonymous callable.');
        }
    }
}
