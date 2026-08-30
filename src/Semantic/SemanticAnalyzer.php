<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Semantic\Binding\BindingTable;
use Amasiye\Ppphp\Semantic\Effect\CallableErrorIndex;
use Amasiye\Ppphp\Semantic\Effect\ErrorResolver;
use Amasiye\Ppphp\Semantic\Pass\CheckBindingsPass;
use Amasiye\Ppphp\Semantic\Pass\CheckErrorEffectsPass;
use Amasiye\Ppphp\Semantic\Pass\CheckTypesPass;
use Amasiye\Ppphp\Semantic\Pass\DeclareSymbolsPass;
use Amasiye\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Amasiye\Ppphp\Semantic\Pass\ResolveNamesPass;
use Amasiye\Ppphp\Semantic\Scope\ScopeStack;
use Amasiye\Ppphp\Semantic\Symbol\SymbolTable;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Support\Path;
use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

final readonly class SemanticAnalyzer
{
    /** @var list<SemanticPass> */
    private array $passes;

    private readonly DeclareSymbolsPass $declareSymbols;

    private readonly ResolveNamesPass $resolveNames;

    private readonly ErrorResolver $errorResolver;

    /** @param list<SemanticPass>|null $passes */
    public function __construct(
        ?array $passes = null,
        ?DeclareSymbolsPass $declareSymbols = null,
        ?ResolveNamesPass $resolveNames = null,
        ?ErrorResolver $errorResolver = null,
    )
    {
        $this->passes = $passes ?? [new CheckBindingsPass(), new CheckTypesPass(), new CheckErrorEffectsPass()];
        $this->declareSymbols = $declareSymbols ?? new DeclareSymbolsPass();
        $this->resolveNames = $resolveNames ?? new ResolveNamesPass();
        $this->errorResolver = $errorResolver ?? new ErrorResolver();
    }

    public function analyze(ProjectParseResult $parseResult, ?ProjectParseResult $contextResult = null): SemanticAnalysisResult
    {
        $diagnostics = new DiagnosticBag();
        $diagnostics->addAll($parseResult->diagnostics);
        $models = [];

        if (!$parseResult->isSuccessful) {
            return new SemanticAnalysisResult($models, $diagnostics);
        }

        $projectParseResult = $this->mergeParseResults($parseResult, $contextResult);
        $symbols = new SymbolTable();
        $resolvedNames = new ResolvedNameTable();
        $errorContracts = new CallableErrorIndex();
        $projectContext = new ProjectSemanticContext(
            $projectParseResult,
            $symbols,
            $resolvedNames,
            $diagnostics,
            $errorContracts,
            array_fill_keys(array_keys($parseResult->parsedFiles), true),
        );
        $this->resolveNames->execute($projectContext);
        $this->declareSymbols->execute($projectContext);
        $this->errorResolver->prepare($projectContext);
        $signatures = $this->buildCallableSignatures($projectParseResult);

        foreach ($parseResult->parsedFiles as $parsedFile) {
            if ($parsedFile->sourceFile->kind !== FileKind::Ppphp) {
                continue;
            }

            $modelDiagnostics = new DiagnosticBag();
            $model = new SemanticModel(
                $parsedFile,
                new BindingTable(),
                $modelDiagnostics,
                $errorContracts,
            );
            $context = new SemanticContext(
                $parsedFile,
                $model,
                new ScopeStack(),
                $signatures,
                $symbols,
                $resolvedNames,
            );

            foreach ($this->passes as $pass) {
                $pass->execute($context);
            }

            $models[Path::buildComparisonKey($parsedFile->sourceFile->path)] = $model;
            $diagnostics->addAll($modelDiagnostics);
        }

        return new SemanticAnalysisResult($models, $diagnostics, $symbols, $resolvedNames);
    }

    private function mergeParseResults(
        ProjectParseResult $selected,
        ?ProjectParseResult $context,
    ): ProjectParseResult {
        if ($context === null) {
            return $selected;
        }

        return new ProjectParseResult(
            array_replace($context->parsedFiles, $selected->parsedFiles),
            array_replace($context->sourceFiles, $selected->sourceFiles),
            new DiagnosticBag(),
        );
    }

    private function buildCallableSignatures(ProjectParseResult $parseResult): CallableSignatureIndex
    {
        $index = new CallableSignatureIndex();

        foreach ($parseResult->parsedFiles as $parsedFile) {
            foreach ($parsedFile->statements as $statement) {
                $this->indexNode($statement, $index);
            }
        }

        return $index;
    }

    private function indexNode(
        Node $node,
        CallableSignatureIndex $index,
        string $namespace = '',
    ): void {
        if ($node instanceof Stmt\Namespace_) {
            $nestedNamespace = $node->name?->toString() ?? '';

            foreach ($node->stmts as $statement) {
                $this->indexNode($statement, $index, $nestedNamespace);
            }

            return;
        }

        if ($node instanceof Stmt\Function_) {
            $shortName = $node->name->toString();
            $qualifiedName = $namespace === '' ? $shortName : $namespace . '\\' . $shortName;
            $positions = $this->resolveByReferencePositions($node->params);
            $index->recordFunction($qualifiedName, $positions);

            if (strcasecmp($qualifiedName, $shortName) !== 0) {
                $index->recordFunction($shortName, $positions);
            }

            foreach ($node->stmts as $statement) {
                $this->indexNode($statement, $index, $namespace);
            }

            return;
        }

        if ($node instanceof Stmt\ClassLike) {
            $shortName = $node->name?->toString();

            if ($shortName !== null) {
                $qualifiedName = $namespace === '' ? $shortName : $namespace . '\\' . $shortName;

                foreach ($node->getMethods() as $method) {
                    $positions = $this->resolveByReferencePositions($method->params);
                    $index->recordMethod($qualifiedName, $method->name->toString(), $positions);

                    if (strcasecmp($qualifiedName, $shortName) !== 0) {
                        $index->recordMethod($shortName, $method->name->toString(), $positions);
                    }
                }
            }

            foreach ($node->stmts as $statement) {
                foreach ($statement->getSubNodeNames() as $subNodeName) {
                    $value = $statement->{$subNodeName};

                    if ($value instanceof Node && !$value instanceof Param) {
                        $this->indexNode($value, $index, $namespace);
                    } elseif (is_array($value)) {
                        foreach ($value as $child) {
                            if ($child instanceof Node && !$child instanceof Param) {
                                $this->indexNode($child, $index, $namespace);
                            }
                        }
                    }
                }
            }

            return;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};

            if ($value instanceof Node && !$value instanceof Param) {
                $this->indexNode($value, $index, $namespace);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node && !$child instanceof Param) {
                        $this->indexNode($child, $index, $namespace);
                    }
                }
            }
        }
    }

    /**
     * @param array<Param> $parameters
     * @return list<int>
     */
    private function resolveByReferencePositions(array $parameters): array
    {
        $positions = [];

        foreach ($parameters as $position => $parameter) {
            if ($parameter->byRef) {
                $positions[] = $position;
            }
        }

        return $positions;
    }
}
