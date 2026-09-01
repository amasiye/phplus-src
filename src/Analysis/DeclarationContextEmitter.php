<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis;

use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Transpilation\GeneratedPhp;
use Amasiye\Ppphp\Transpilation\GeneratedSourceMap;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** Produces analysis-only declarations without retaining implementation bodies. */
final readonly class DeclarationContextEmitter
{
    public function __construct(
        private ParserFactory $parsers = new ParserFactory(),
        private Standard $printer = new Standard(),
    ) {}

    public function emit(SourceFile $sourceFile, string $loweredPhp): GeneratedPhp
    {
        $statements = $this->parsers->createForNewestSupportedVersion()->parse($loweredPhp);

        if ($statements === null) {
            throw new \RuntimeException('The lowered declaration context could not be parsed.');
        }

        $contents = $this->printer->prettyPrintFile($this->retainDeclarations(array_values($statements))) . "\n";

        return new GeneratedPhp(
            $contents,
            new GeneratedSourceMap($sourceFile, strlen($contents), []),
            [],
        );
    }

    /**
     * @param list<Node\Stmt> $statements
     * @return list<Node\Stmt>
     */
    private function retainDeclarations(array $statements): array
    {
        $declarations = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                $statement->stmts = $this->retainDeclarations(array_values($statement->stmts));
                $declarations[] = $statement;
                continue;
            }

            if ($statement instanceof Node\Stmt\Use_
                || $statement instanceof Node\Stmt\GroupUse
                || $statement instanceof Node\Stmt\Const_) {
                $declarations[] = $statement;
                continue;
            }

            if ($statement instanceof Node\Stmt\Function_) {
                $statement->stmts = $this->placeholderBody($statement->returnType);
                $declarations[] = $statement;
                continue;
            }

            if (!$statement instanceof Node\Stmt\ClassLike) {
                continue;
            }

            foreach ($statement->getMethods() as $method) {
                if ($method->stmts !== null) {
                    $method->stmts = $this->placeholderBody($method->returnType);
                }
            }

            $declarations[] = $statement;
        }

        return $declarations;
    }

    /** @return list<Node\Stmt> */
    private function placeholderBody(Node\Identifier|Node\Name|Node\ComplexType|null $returnType): array
    {
        if ($returnType instanceof Node\Identifier
            && in_array(strtolower($returnType->toString()), ['void', 'never'], true)) {
            return $returnType->toString() === 'never'
                ? [$this->throwPlaceholder()]
                : [];
        }

        return [$this->throwPlaceholder()];
    }

    private function throwPlaceholder(): Node\Stmt\Expression
    {
        return new Node\Stmt\Expression(new Node\Expr\Throw_(
            new Node\Expr\New_(new Node\Name\FullyQualified('LogicException')),
        ));
    }
}
