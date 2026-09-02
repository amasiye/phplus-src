<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Semantic\Generic\GenericDeclarationIndexer;
use Amasiye\Ppphp\Semantic\Binding\BindingTable;
use Amasiye\Ppphp\Semantic\Effect\CallableErrorIndex;
use Amasiye\Ppphp\Semantic\Effect\ErrorResolver;
use Amasiye\Ppphp\Semantic\Pass\CheckBindingsPass;
use Amasiye\Ppphp\Semantic\Pass\CheckErrorEffectsPass;
use Amasiye\Ppphp\Semantic\Pass\CheckGenericTypesPass;
use Amasiye\Ppphp\Semantic\Pass\CheckStrictTypesDeclarationPass;
use Amasiye\Ppphp\Semantic\Pass\CheckTypesPass;
use Amasiye\Ppphp\Semantic\Pass\CheckWhenExpressionsPass;
use Amasiye\Ppphp\Semantic\Pass\DeclareSymbolsPass;
use Amasiye\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Amasiye\Ppphp\Semantic\Pass\ResolveNamesPass;
use Amasiye\Ppphp\Semantic\Pass\AnalyzeTypeFlowPass;
use Amasiye\Ppphp\Semantic\Scope\ScopeStack;
use Amasiye\Ppphp\Semantic\Symbol\SymbolTable;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Support\Path;

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
        $this->passes = $passes ?? [
            new CheckBindingsPass(),
            new CheckWhenExpressionsPass(),
            new CheckStrictTypesDeclarationPass(),
            new CheckTypesPass(),
            new CheckGenericTypesPass(),
            new AnalyzeTypeFlowPass(),
            new CheckErrorEffectsPass(),
        ];
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
        $preliminarySymbols = new SymbolTable();
        $preliminarySymbols->registerKnownClassPrefixes($projectParseResult->knownClassPrefixes);
        $preliminarySymbols->registerClassAliases($projectParseResult->classAliases);
        $resolvedNames = new ResolvedNameTable();
        $errorContracts = new CallableErrorIndex();
        $preliminaryContext = new ProjectSemanticContext(
            $projectParseResult,
            $preliminarySymbols,
            $resolvedNames,
            new DiagnosticBag(),
            $errorContracts,
            array_fill_keys(array_keys($parseResult->parsedFiles), true),
        );
        $this->resolveNames->execute($preliminaryContext);
        $this->declareSymbols->execute($preliminaryContext);
        $preliminaryGenerics = (new GenericDeclarationIndexer())->build($projectParseResult, $preliminarySymbols);

        $symbols = new SymbolTable();
        $symbols->registerKnownClassPrefixes($projectParseResult->knownClassPrefixes);
        $symbols->registerClassAliases($projectParseResult->classAliases);
        $projectContext = new ProjectSemanticContext(
            $projectParseResult,
            $symbols,
            $resolvedNames,
            $diagnostics,
            $errorContracts,
            array_fill_keys(array_keys($parseResult->parsedFiles), true),
        );
        $this->declareSymbols->execute($projectContext, $preliminaryGenerics);
        $genericDeclarations = (new GenericDeclarationIndexer())->build($projectParseResult, $symbols);
        $this->errorResolver->prepare($projectContext);
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
                $symbols,
                $resolvedNames,
                $genericDeclarations,
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
            array_values(array_unique([
                ...$context->knownClassPrefixes,
                ...$selected->knownClassPrefixes,
            ])),
            array_replace($context->classAliases, $selected->classAliases),
            array_replace($context->classAliasProvenance, $selected->classAliasProvenance),
        );
    }

}
