<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Effect;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticLabel;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Frontend\Ast\ThrowsClause;
use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Interop\PhpDoc\PhpDocReader;
use Amasiye\Ppphp\Interop\PhpDoc\PhpDocThrowsImporter;
use Amasiye\Ppphp\Semantic\Effect\Enumerations\ThrowableKind;
use Amasiye\Ppphp\Semantic\ProjectSemanticContext;
use Amasiye\Ppphp\Semantic\SourceNameResolver;
use Amasiye\Ppphp\Semantic\Symbol\FunctionSymbol;
use Amasiye\Ppphp\Semantic\Symbol\MethodSymbol;
use Amasiye\Ppphp\Semantic\When\WhenFragmentParser;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\Span;
use Amasiye\Ppphp\Support\Path;
use PhpParser\Node;
use PhpParser\Node\Stmt;

final readonly class ErrorResolver
{
    public function __construct(
        private PhpDocReader $phpDoc = new PhpDocReader(),
        private PhpDocThrowsImporter $phpDocThrows = new PhpDocThrowsImporter(),
        private SourceNameResolver $names = new SourceNameResolver(),
        private WhenFragmentParser $whenFragments = new WhenFragmentParser(),
    ) {}

    public function prepare(ProjectSemanticContext $context): void
    {
        $hierarchy = new ThrowableHierarchy($context->symbols);

        foreach ($context->parseResult->parsedFiles as $file) {
            $this->prepareStatements($file->statements, $file, $context, $hierarchy, '');

            foreach ($file->extensionSyntax->whenExpressions as $when) {
                $namespace = $this->names->resolveNamespaceAt($file, $when->span->start->offset);
                foreach ([...$when->branches, $when->elseBranch] as $branch) {
                    $fragment = $this->whenFragments->parseBody($file, $branch->bodySpan);

                    if ($fragment->isSuccessful) {
                        $this->prepareStatements(
                            $fragment->statements,
                            $file,
                            $context,
                            $hierarchy,
                            $namespace,
                        );
                    }
                }
            }

            foreach ($file->extensionSyntax->throwsClauses as $clause) {
                if ($context->errorContracts->find($file->sourceFile, $clause) === null) {
                    throw new \LogicException(sprintf(
                        'The throws clause owned by %s could not be associated with its callable.',
                        $clause->ownerNameSpan->text,
                    ));
                }
            }
        }
    }

    /** @param list<Stmt> $statements */
    private function prepareStatements(
        array $statements,
        ParsedFile $file,
        ProjectSemanticContext $context,
        ThrowableHierarchy $hierarchy,
        string $namespace,
    ): void {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->prepareStatements(
                    array_values($statement->stmts),
                    $file,
                    $context,
                    $hierarchy,
                    $statement->name?->toString() ?? '',
                );
                continue;
            }

            if ($statement instanceof Stmt\Function_) {
                $name = $this->qualify($namespace, $statement->name->toString());
                $symbol = $context->symbols->findFunction($name);

                if ($symbol !== null && $symbol->sourceFile === $file->sourceFile) {
                    $this->prepareFunction($statement, $symbol, $file, $context, $hierarchy);
                }

                continue;
            }

            if (!$statement instanceof Stmt\ClassLike || $statement->name === null) {
                continue;
            }

            $owner = $this->qualify($namespace, $statement->name->toString());
            $class = $context->symbols->findClass($owner);

            if ($class === null || $class->sourceFile !== $file->sourceFile) {
                continue;
            }

            foreach ($statement->getMethods() as $method) {
                $symbol = $class->findMethod($method->name->toString());

                if ($symbol !== null) {
                    $this->prepareMethod($method, $symbol, $file, $context, $hierarchy);
                }
            }
        }
    }

    private function prepareFunction(
        Stmt\Function_ $node,
        FunctionSymbol $symbol,
        ParsedFile $file,
        ProjectSemanticContext $context,
        ThrowableHierarchy $hierarchy,
    ): void {
        $symbol->replaceErrorContract($this->resolveContract($node, $file, $context, $hierarchy, false));
    }

    private function prepareMethod(
        Stmt\ClassMethod $node,
        MethodSymbol $symbol,
        ParsedFile $file,
        ProjectSemanticContext $context,
        ThrowableHierarchy $hierarchy,
    ): void {
        $destructor = strtolower($node->name->toString()) === '__destruct';
        $contract = $this->resolveContract($node, $file, $context, $hierarchy, $destructor);
        $symbol->replaceErrorContract($contract);
    }

    private function resolveContract(
        Stmt\Function_|Stmt\ClassMethod $node,
        ParsedFile $file,
        ProjectSemanticContext $context,
        ThrowableHierarchy $hierarchy,
        bool $destructor,
    ): CallableErrorContract {
        $ownerSpan = $this->resolveNodeSpan($file, $node);
        $clause = $this->findNativeClause($file, $node);
        $tags = $this->phpDoc->readThrows($node->getDocComment(), $file->sourceFile);
        $documented = $this->phpDocThrows->import($file, $tags);
        $native = $clause === null
            ? new ErrorSet()
            : $this->resolveNativeErrors($clause, $file, $context, $hierarchy);
        $phpDocSpan = $tags[0]->documentSpan ?? null;

        foreach ($documented as $error) {
            if ($hierarchy->classify($error->canonicalType) === ThrowableKind::NotThrowable) {
                $this->addNotThrowable($context, $error->span, $error->canonicalType);
            }
        }

        foreach ($tags as $tag) {
            if (
                strtolower(trim($tag->typeExpression)) !== 'void'
                && (
                    str_contains($tag->typeExpression, '?')
                    || str_contains($tag->typeExpression, '&')
                    || str_contains($tag->typeExpression, '<')
                    || str_contains($tag->typeExpression, '>')
                )
            ) {
                $this->addNotThrowable($context, $tag->typeSpan, $tag->typeExpression);
            }
        }

        if ($file->sourceFile->kind === FileKind::Ppphp) {
            if ($clause === null && !$documented->isEmpty) {
                $this->addDiagnostic(
                    $context,
                    DiagnosticCode::NativeThrowsClauseRequired,
                    'Native Throws Clause Is Required',
                    'A ++PHP callable must use native throws syntax for a non-empty checked-error contract.',
                    $phpDocSpan ?? $ownerSpan,
                );
            } elseif ($clause !== null && $tags !== [] && !$this->compareErrorSets($native, $documented)) {
                $this->addDiagnostic(
                    $context,
                    DiagnosticCode::ThrowsDocumentationConflictsWithNativeClause,
                    'Throws Documentation Conflicts With Native Clause',
                    'The existing @throws documentation does not match the native throws clause.',
                    $phpDocSpan ?? $clause->span,
                    [new DiagnosticLabel($clause->span, 'The native throws contract is declared here.')],
                );
            }

            $resolved = $native;
        } else {
            $resolved = $documented;
        }

        if ($destructor) {
            foreach ($resolved as $error) {
                if ($hierarchy->classify($error->canonicalType) !== ThrowableKind::Checked) {
                    continue;
                }

                $this->addDiagnostic(
                    $context,
                    DiagnosticCode::CheckedErrorCannotEscapeDestructor,
                    'Checked Error Cannot Escape Destructor',
                    sprintf('Destructor contracts cannot expose checked error %s.', $error->canonicalType),
                    $error->span,
                );
            }
        }

        $contract = new CallableErrorContract($resolved, $ownerSpan, $clause, $phpDocSpan);

        if ($clause !== null) {
            $context->errorContracts->record($file->sourceFile, $clause, $contract);
        }

        return $contract;
    }

    private function findNativeClause(
        ParsedFile $file,
        Stmt\Function_|Stmt\ClassMethod $node,
    ): ?ThrowsClause {
        foreach ($file->extensionSyntax->throwsClauses as $clause) {
            $nameSpan = $this->resolveNodeSpan($file, $node->name);

            if (
                $clause->ownerNameSpan->start->offset === $nameSpan->start->offset
                && $clause->ownerNameSpan->end->offset === $nameSpan->end->offset
                && $clause->ownerDeclarationSpan->start->offset <= $clause->ownerNameSpan->start->offset
                && $clause->ownerDeclarationSpan->end->offset >= $clause->ownerNameSpan->end->offset
                && $clause->ownerNameSpan->text === $node->name->toString()
            ) {
                return $clause;
            }
        }

        return null;
    }

    private function resolveNodeSpan(ParsedFile $file, Node $node): Span
    {
        $originalStart = $node->getAttribute('ppphpOriginalStart');
        $originalEnd = $node->getAttribute('ppphpOriginalEnd');

        if (is_int($originalStart) && is_int($originalEnd)) {
            return $file->sourceFile->createSpan($originalStart, $originalEnd);
        }

        return $file->sourceFile->createSpan(
            max(0, $node->getStartFilePos()),
            min($file->sourceFile->length, max(0, $node->getEndFilePos() + 1)),
        );
    }

    private function resolveNativeErrors(
        ThrowsClause $clause,
        ParsedFile $file,
        ProjectSemanticContext $context,
        ThrowableHierarchy $hierarchy,
    ): ErrorSet {
        $errors = new ErrorSet();

        foreach ($clause->errorTypes as $sourceType) {
            $invalidBuiltin = in_array(
                strtolower(trim($sourceType->text, ' ()')),
                ['array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'resource', 'string', 'true', 'void'],
                true,
            );

            if (
                $invalidBuiltin
                || str_contains($sourceType->text, '?')
                || str_contains($sourceType->text, '&')
                || $sourceType->genericReferences !== []
            ) {
                $this->addNotThrowable($context, $sourceType->span, $sourceType->text);
                continue;
            }

            $cursor = 0;

            foreach (explode('|', $sourceType->text) as $part) {
                $written = trim($part, " \t\n\r\0\x0B()");
                $relative = strpos($sourceType->text, $written, $cursor);

                if ($relative === false || $written === '') {
                    continue;
                }

                $cursor = $relative + strlen($written);
                $span = $file->sourceFile->createSpan(
                    $sourceType->span->start->offset + $relative,
                    $sourceType->span->start->offset + $relative + strlen($written),
                );
                if (in_array(strtolower($written), [
                    'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
                    'mixed', 'never', 'null', 'object', 'resource', 'string', 'true', 'void',
                ], true)) {
                    $this->addNotThrowable($context, $span, $written);
                    continue;
                }

                $canonical = $this->names->resolve($file, $written, $span->start->offset);
                $occurrence = new ErrorOccurrence($canonical, $span, $clause->span);
                $classification = $hierarchy->classify($canonical);

                if ($classification === ThrowableKind::NotThrowable) {
                    $this->addNotThrowable($context, $span, $canonical);
                    continue;
                }

                if (!$errors->add($occurrence)) {
                    $this->addDiagnostic(
                        $context,
                        DiagnosticCode::DuplicateErrorDeclaration,
                        'Duplicate Error Declaration',
                        sprintf('%s appears more than once in this throws contract.', $canonical),
                        $span,
                    );
                }
            }
        }

        return $errors;
    }

    private function compareErrorSets(ErrorSet $left, ErrorSet $right): bool
    {
        $leftTypes = array_map(strtolower(...), $left->types);
        $rightTypes = array_map(strtolower(...), $right->types);
        sort($leftTypes, SORT_STRING);
        sort($rightTypes, SORT_STRING);

        return $leftTypes === $rightTypes;
    }

    private function addNotThrowable(ProjectSemanticContext $context, Span $span, string $type): void
    {
        $this->addDiagnostic(
            $context,
            DiagnosticCode::ErrorTypeNotThrowable,
            'Error Type Is Not Throwable',
            sprintf('%s is not a valid Throwable type.', $type),
            $span,
        );
    }

    /** @param list<DiagnosticLabel> $related */
    private function addDiagnostic(
        ProjectSemanticContext $context,
        DiagnosticCode $code,
        string $title,
        string $message,
        Span $span,
        array $related = [],
    ): void {
        if (!isset($context->diagnosticSourceFiles[Path::buildComparisonKey($span->sourceFile->path)])) {
            return;
        }

        $context->diagnostics->add(new Diagnostic(
            $code,
            Severity::Error,
            $title,
            $message,
            new DiagnosticLabel($span, $message),
            $related,
        ));
    }

    private function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }
}
