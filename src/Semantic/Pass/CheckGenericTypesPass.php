<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Pass;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticLabel;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\Ast\GenericDeclaration;
use Amasiye\Ppphp\Frontend\Ast\GenericType as SourceGenericType;
use Amasiye\Ppphp\Frontend\Ast\SourceType;
use Amasiye\Ppphp\Interop\PhpDoc\PhpDocMetadata;
use Amasiye\Ppphp\Interop\PhpDoc\PhpDocReader;
use Amasiye\Ppphp\Semantic\Generic\GenericDeclarationEntry;
use Amasiye\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Amasiye\Ppphp\Semantic\SemanticContext;
use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Semantic\Symbol\MethodSymbol;
use Amasiye\Ppphp\Semantic\Type\AtomicType;
use Amasiye\Ppphp\Semantic\Type\CompositeTypeParser;
use Amasiye\Ppphp\Semantic\Type\CompositeTypeValidator;
use Amasiye\Ppphp\Semantic\Type\GenericType;
use Amasiye\Ppphp\Semantic\Type\IntersectionType;
use Amasiye\Ppphp\Semantic\Type\LocalType;
use Amasiye\Ppphp\Semantic\Type\TypeCompatibility;
use Amasiye\Ppphp\Semantic\Type\TypeName;
use Amasiye\Ppphp\Semantic\Type\SourceTypeResolver;
use Amasiye\Ppphp\Semantic\Type\TypeParameter;
use Amasiye\Ppphp\Semantic\Type\TypeSubstitution;
use Amasiye\Ppphp\Semantic\Type\TypedArrayType;
use Amasiye\Ppphp\Semantic\Type\UnionType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Source\Span;
use Amasiye\Ppphp\Support\Path;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class CheckGenericTypesPass implements SemanticPass
{
    private SemanticContext $context;

    /** @var array<string, true> */
    private array $reported = [];

    public function __construct(
        private readonly CompositeTypeParser $types = new CompositeTypeParser(),
        private readonly TypeCompatibility $compatibility = new TypeCompatibility(),
        private readonly PhpDocReader $phpDoc = new PhpDocReader(),
        private readonly CompositeTypeValidator $compositeTypes = new CompositeTypeValidator(),
        private readonly SourceTypeResolver $sourceTypes = new SourceTypeResolver(),
    ) {}

    public function execute(SemanticContext $context): void
    {
        $this->context = $context;
        $this->reported = [];

        foreach ($context->parsedFile->extensionSyntax->genericDeclarations as $declaration) {
            $this->validateDeclaration($declaration);
        }

        foreach ($context->parsedFile->extensionSyntax->genericTypes as $reference) {
            foreach ($reference->arguments as $argument) {
                $this->validateCompositeSourceType($argument);
            }

            if ($reference->isTypedArray) {
                $this->validateTypedArrayReference($reference);

                continue;
            }

            $this->validateReference($reference);
        }

        foreach ($context->parsedFile->extensionSyntax->typedLocals as $declaration) {
            $this->validateRawSourceType($declaration->type);
            $this->validateStaticSourceType($declaration->type);
        }

        foreach ($context->parsedFile->extensionSyntax->typedForInitializers as $declaration) {
            $this->validateRawSourceType($declaration->type);
            $this->validateStaticSourceType($declaration->type);
        }

        foreach ($context->parsedFile->extensionSyntax->typedForeachBindings as $declaration) {
            $this->validateRawSourceType($declaration->type);
            $this->validateStaticSourceType($declaration->type);
        }

        foreach ($context->parsedFile->statements as $statement) {
            $this->inspectNode($statement);
        }
    }

    private function validateDeclaration(GenericDeclaration $declaration): void
    {
        $entry = $this->context->genericDeclarations->findDeclaration($this->context->parsedFile->sourceFile, $declaration);

        if ($entry === null) {
            $this->addDiagnostic(
                DiagnosticCode::GenericStaticAnalysisError,
                'The generic owner could not be associated with its exact project symbol.',
                $declaration->span,
            );

            return;
        }

        $seen = [];
        $outerDeclarations = array_filter(
            $this->context->genericDeclarations->findVisibleDeclarations($entry->sourceFile, $entry->genericSpan->start->offset),
            static fn (GenericDeclarationEntry $candidate): bool => $candidate->key !== $entry->key,
        );

        foreach ($entry->parameters as $index => $parameter) {
            $sourceBound = $declaration->parameters[$index]->bound ?? null;

            if ($sourceBound !== null) {
                $this->validateCompositeSourceType($sourceBound);
                $this->validateRawSourceType($sourceBound);
            }

            $nameKey = strtolower($parameter->name);

            if (isset($seen[$nameKey])) {
                $this->addDiagnostic(
                    DiagnosticCode::DuplicateTypeParameter,
                    sprintf('Type parameter %s is declared more than once on %s.', $parameter->name, $entry->name),
                    $parameter->span,
                );
            }

            $seen[$nameKey] = true;

            foreach ($outerDeclarations as $outer) {
                if ($outer->findParameter($parameter->name) !== null) {
                    $this->addDiagnostic(
                        DiagnosticCode::DuplicateTypeParameter,
                        sprintf('Type parameter %s shadows a parameter declared by the enclosing generic owner.', $parameter->name),
                        $parameter->span,
                    );
                }
            }

            if ($parameter->bound !== null && !$this->isValidBound($parameter->bound, $entry)) {
                $this->addDiagnostic(
                    DiagnosticCode::InvalidGenericBound,
                    sprintf('The upper bound for %s must be one class or interface type, or an intersection of them.', $parameter->name),
                    $parameter->span,
                );
            }
        }
    }

    private function isValidBound(Type $bound, GenericDeclarationEntry $entry): bool
    {
        if ($bound instanceof UnionType || $bound instanceof TypedArrayType) {
            return false;
        }

        $members = $bound instanceof IntersectionType ? $bound->members : [$bound];

        foreach ($members as $member) {
            if ($member instanceof TypeParameter) {
                continue;
            }

            $base = $member instanceof GenericType ? $member->base : $member;

            if (!$base instanceof AtomicType
                || $base->isBuiltin
                || $entry->findParameter(TypeName::resolveShort($base->name)) !== null) {
                return false;
            }

            $symbol = $this->findClass($base->name);

            if ($symbol !== null && !in_array($symbol->kind, ['class', 'interface'], true)) {
                return false;
            }
        }

        return true;
    }

    private function validateReference(SourceGenericType $reference): void
    {
        $name = $reference->nameSpan->text;
        $namespace = $this->resolveNamespaceAt($reference->span->start->offset);
        $declaration = $this->context->genericDeclarations->findType($name, $namespace);

        if ($declaration === null) {
            $knownType = $this->findClass($name);

            if ($knownType !== null) {
                $this->addDiagnostic(
                    DiagnosticCode::TypeIsNotGeneric,
                    sprintf('Type %s does not declare generic parameters.', $name),
                    $reference->nameSpan,
                );
            }

            return;
        }

        if (count($reference->arguments) !== count($declaration->parameters)) {
            $this->addDiagnostic(
                DiagnosticCode::GenericTypeArgumentCountDoesNotMatch,
                sprintf(
                    '%s requires %d type %s, but %d were provided.',
                    $declaration->name,
                    count($declaration->parameters),
                    count($declaration->parameters) === 1 ? 'argument' : 'arguments',
                    count($reference->arguments),
                ),
                $reference->argumentListSpan,
                [new DiagnosticLabel(
                    $declaration->genericSpan,
                    sprintf('Generic type %s declares its parameters here.', $declaration->name),
                )],
            );

            return;
        }

        $substitutions = [];

        foreach ($reference->arguments as $index => $argument) {
            $this->validateParameterNames($argument);
            $this->validateRawSourceType($argument);
            $parameter = $declaration->parameters[$index];

            $actualType = $this->sourceTypes->resolveSourceType(
                $argument,
                $this->context->parsedFile,
                $this->context->genericDeclarations,
            );

            if ($parameter->bound === null) {
                $substitutions[$parameter->canonical] = $actualType;
                continue;
            }

            $declaredBound = LocalType::createFromSemanticType(
                (new TypeSubstitution($substitutions))->substitute($parameter->bound),
            );
            $actual = LocalType::createFromSemanticType($actualType);

            if (!$this->compatibility->accepts($declaredBound, $actual, $this->context->symbols)) {
                $this->addDiagnostic(
                    DiagnosticCode::TypeArgumentDoesNotSatisfyBound,
                    sprintf(
                        'Type argument %s does not satisfy the %s bound %s.',
                        $argument->text,
                        $parameter->name,
                        $parameter->bound->renderPhpDoc(),
                    ),
                    $argument->span,
                    [new DiagnosticLabel(
                        $parameter->span,
                        sprintf(
                            'Type parameter %s is declared here with bound %s.',
                            $parameter->name,
                            $parameter->bound->renderPhpDoc(),
                        ),
                    )],
                );
            }

            $substitutions[$parameter->canonical] = $actualType;
        }
    }

    private function validateTypedArrayReference(SourceGenericType $reference): void
    {
        if (count($reference->arguments) !== 2) {
            return;
        }

        $sourceType = $reference->arguments[0];
        $type = $this->types->parse($sourceType->text);

        if ($this->isValidArrayKeyType($type, $sourceType)) {
            return;
        }

        $this->addDiagnostic(
            DiagnosticCode::TypedArrayKeyTypeIsInvalid,
            sprintf('Typed array key type %s is outside PHP\'s int|string array-key domain.', $sourceType->text),
            $sourceType->span,
        );
    }

    private function isValidArrayKeyType(Type $type, SourceType $sourceType): bool
    {
        if ($type instanceof AtomicType) {
            if (in_array($type->canonical, ['int', 'string'], true)) {
                return true;
            }

            $parameter = $this->context->genericDeclarations->findVisibleParameter(
                $this->context->parsedFile->sourceFile,
                $sourceType->span->start->offset,
                $type->name,
            );

            return $parameter !== null
                && $parameter->bound !== null
                && $this->isCanonicalArrayKeySubset($parameter->bound);
        }

        return $this->isCanonicalArrayKeySubset($type);
    }

    private function isCanonicalArrayKeySubset(Type $type): bool
    {
        if ($type instanceof AtomicType) {
            return in_array($type->canonical, ['int', 'string'], true);
        }

        if (!$type instanceof UnionType || count($type->members) < 2) {
            return false;
        }

        foreach ($type->members as $member) {
            if (!$member instanceof AtomicType || !in_array($member->canonical, ['int', 'string'], true)) {
                return false;
            }
        }

        return true;
    }

    private function validateParameterNames(SourceType $sourceType): void
    {
        foreach ($this->collectAtomicTypes($this->types->parse($sourceType->text)) as $atomic) {
            if (!$this->context->genericDeclarations->containsParameterName($atomic->name)) {
                continue;
            }

            if ($this->context->genericDeclarations->findVisibleParameter(
                $this->context->parsedFile->sourceFile,
                $sourceType->span->start->offset,
                $atomic->name,
            ) === null) {
                $this->addDiagnostic(
                    DiagnosticCode::UnknownTypeParameter,
                    sprintf('Type parameter %s is not visible in this declaration scope.', $atomic->name),
                    $sourceType->span,
                );
            }
        }
    }

    private function validateCompositeSourceType(SourceType $sourceType): void
    {
        foreach ($this->compositeTypes->validateLocal($sourceType->text) as $message) {
            $this->addDiagnostic(
                DiagnosticCode::InvalidCompositeType,
                $message,
                $sourceType->span,
            );
        }
    }

    private function validateRawSourceType(SourceType $sourceType): void
    {
        $this->validateParameterNames($sourceType);
        $this->validateRawType($this->types->parse($sourceType->text), $sourceType->span);
    }

    private function validateRawType(Type $type, Span $span): void
    {
        if ($type instanceof AtomicType) {
            if ($this->context->genericDeclarations->findType($type->name, $this->resolveNamespaceAt($span->start->offset)) !== null) {
                $this->addDiagnostic(
                    DiagnosticCode::GenericTypeArgumentsAreRequired,
                    sprintf('Generic type %s requires explicit type arguments in ++PHP source.', $type->name),
                    $span,
                );
            }

            return;
        }

        $members = match (true) {
            $type instanceof GenericType => $type->arguments,
            $type instanceof TypedArrayType => [$type->keyType, $type->valueType],
            $type instanceof UnionType, $type instanceof IntersectionType => $type->members,
            default => [],
        };

        foreach ($members as $member) {
            $this->validateRawType($member, $span);
        }
    }

    private function validateStaticSourceType(SourceType $sourceType): void
    {
        $method = $this->findContainingStaticMethod($sourceType->span->start->offset);

        if ($method === null) {
            return;
        }

        $class = $this->context->symbols->findClass($method->owner);
        $classGeneric = $class?->genericDeclaration;

        if ($classGeneric === null) {
            return;
        }

        foreach ($this->collectAtomicTypes($this->types->parse($sourceType->text)) as $atomic) {
            if ($classGeneric->findParameter($atomic->name) !== null) {
                $this->addDiagnostic(
                    DiagnosticCode::StaticMemberCannotUseClassTypeParameter,
                    sprintf('Static method %s::%s cannot use class type parameter %s.', $method->owner, $method->name, $atomic->name),
                    $sourceType->span,
                );
            }
        }
    }

    private function inspectNode(Node $node): void
    {
        $this->validateNativeDocumentation($node);

        if ($node instanceof Stmt\Function_) {
            foreach ($node->params as $parameter) {
                $this->inspectNativeType($parameter->type, false);
            }
            $this->inspectNativeType($node->returnType, false);
        } elseif ($node instanceof Stmt\ClassMethod) {
            foreach ($node->params as $parameter) {
                $this->inspectNativeType($parameter->type, $node->isStatic());
            }
            $this->inspectNativeType($node->returnType, $node->isStatic());
        } elseif ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $insideStaticMethod = $this->findContainingStaticMethod(
                $this->createNodeSpan($node)->start->offset,
            ) !== null;

            foreach ($node->params as $parameter) {
                $this->inspectNativeType($parameter->type, $insideStaticMethod);
            }
            $this->inspectNativeType($node->returnType, $insideStaticMethod);
        } elseif ($node instanceof Stmt\Property) {
            $this->inspectNativeType($node->type, $node->isStatic());
        } elseif ($node instanceof Stmt\ClassConst) {
            $this->inspectNativeType($node->type, true);
        } elseif ($node instanceof Stmt\Class_) {
            $this->inspectNativeType($node->extends, false);
            foreach ($node->implements as $interface) {
                $this->inspectNativeType($interface, false);
            }
        } elseif ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $this->inspectNativeType($interface, false);
            }
        } elseif ($node instanceof Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->inspectNativeType($trait, false);
            }
        }

        if ($node instanceof Expr\New_
            || $node instanceof Expr\Instanceof_
            || $node instanceof Expr\StaticCall
            || $node instanceof Expr\ClassConstFetch) {
            $class = $node->class;

            if ($class instanceof Node\Name && $this->findVisibleParameter($class) !== null) {
                $this->addDiagnostic(
                    DiagnosticCode::GenericRuntimeOperationIsNotAllowed,
                    sprintf('Runtime operation on erased type parameter %s is not allowed.', $class->toString()),
                    $this->createNodeSpan($class),
                );
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->inspectNode($value);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->inspectNode($child);
                    }
                }
            }
        }
    }

    private function validateNativeDocumentation(Node $node): void
    {
        if (!$node instanceof Stmt\ClassLike
            && !$node instanceof Stmt\Function_
            && !$node instanceof Stmt\ClassMethod
            && !$node instanceof Stmt\Property
            && !$node instanceof Stmt\TraitUse) {
            return;
        }

        $document = $node->getDocComment();

        if ($document === null) {
            return;
        }

        $metadata = $this->phpDoc->readMetadata($document);

        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
            $this->validateTemplateDocumentation($node, $metadata);
        }

        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
            foreach ($node->params as $parameter) {
                if ($parameter->type === null || !$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name)) {
                    continue;
                }

                $expected = $this->resolveDocumentedExtensionType($parameter->type);
                $actual = $metadata->parameters['$' . $parameter->var->name] ?? null;

                if ($expected !== null && $actual !== null) {
                    $this->validateDocumentedType($expected, $actual, $node);
                }
            }

            if ($node->returnType !== null) {
                $expected = $this->resolveDocumentedExtensionType($node->returnType);
                $actual = $metadata->returns[0] ?? null;

                if ($expected !== null && $actual !== null) {
                    $this->validateDocumentedType($expected, $actual, $node);
                }
            }
        } elseif ($node instanceof Stmt\Property && $node->type !== null) {
            $expected = $this->resolveDocumentedExtensionType($node->type);
            $actual = array_values($metadata->variables)[0] ?? null;

            if ($expected !== null && $actual !== null) {
                $this->validateDocumentedType($expected, $actual, $node);
            }
        }

        if ($node instanceof Stmt\Class_) {
            if ($node->extends !== null) {
                $this->validateDocumentedInheritance($node->extends, $metadata->extends, $node);
            }

            foreach ($node->implements as $interface) {
                $this->validateDocumentedInheritance($interface, $metadata->implements, $node);
            }
        } elseif ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $this->validateDocumentedInheritance($interface, $metadata->extends, $node);
            }
        } elseif ($node instanceof Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->validateDocumentedInheritance($trait, $metadata->uses, $node);
            }
        }
    }

    private function validateTemplateDocumentation(
        Stmt\ClassLike|Stmt\Function_|Stmt\ClassMethod $owner,
        PhpDocMetadata $metadata,
    ): void {
        if ($metadata->templates === []) {
            return;
        }

        $name = $owner->name;
        $declaration = $name === null ? null : $this->findGenericDeclarationAt($name->getStartFilePos());

        if ($declaration === null || count($metadata->templates) !== count($declaration->parameters)) {
            $this->addDocumentationConflict($owner, 'PHPDoc template parameters do not match the native ++PHP declaration.');

            return;
        }

        foreach ($declaration->parameters as $index => $parameter) {
            $documented = $metadata->templates[$index];
            $nativeBound = $parameter->bound?->text;

            if ($documented['name'] !== $parameter->nameSpan->text
                || !$this->documentedTypesMatch($nativeBound, $documented['bound'])) {
                $this->addDocumentationConflict($owner, 'PHPDoc template names or bounds conflict with native ++PHP syntax.');

                return;
            }
        }
    }

    /** @param list<string> $documentedTypes */
    private function validateDocumentedInheritance(Node\Name $native, array $documentedTypes, Node $owner): void
    {
        $expected = $this->resolveDocumentedExtensionType($native);

        if ($expected === null || $documentedTypes === []) {
            return;
        }

        foreach ($documentedTypes as $documented) {
            if ($this->documentedTypesMatch($expected, $documented)) {
                return;
            }
        }

        $this->addDocumentationConflict($owner, sprintf('PHPDoc generic inheritance conflicts with native type %s.', $expected));
    }

    private function validateDocumentedType(string $expected, string $actual, Node $owner): void
    {
        if (!$this->documentedTypesMatch($expected, $actual)) {
            $this->addDocumentationConflict($owner, sprintf('PHPDoc type %s conflicts with native ++PHP type %s.', $actual, $expected));
        }
    }

    private function documentedTypesMatch(?string $native, ?string $documented): bool
    {
        if ($native === null || $documented === null) {
            return $native === $documented;
        }

        return $this->types->parse($this->normalizeDocumentedType($native))->canonical
            === $this->types->parse($this->normalizeDocumentedType($documented))->canonical;
    }

    private function normalizeDocumentedType(string $type): string
    {
        $normalized = preg_replace('/\blist\s*</i', 'array<', $type) ?? $type;

        return strcasecmp(trim($normalized), 'array-key') === 0 ? 'int|string' : $normalized;
    }

    private function resolveDocumentedExtensionType(Node $node): ?string
    {
        [$type, $hasExtension] = $this->resolveDocumentedType($node);

        return $hasExtension ? $type->renderPhpDoc() : null;
    }

    /** @return array{Type, bool} */
    private function resolveDocumentedType(Node $node): array
    {
        if ($node instanceof Node\Name || $node instanceof Node\Identifier) {
            foreach ($this->context->parsedFile->extensionSyntax->genericTypes as $reference) {
                if ($reference->nameSpan->start->offset === $node->getStartFilePos()) {
                    return [$this->types->parse($reference->span->text), true];
                }
            }

            $parameter = $node instanceof Node\Name ? $this->findVisibleParameter($node) : null;

            return [$parameter ?? new AtomicType($node->toString()), $parameter !== null];
        }

        if ($node instanceof Node\NullableType) {
            [$inner, $hasExtension] = $this->resolveDocumentedType($node->type);

            return [new UnionType([$inner, new AtomicType('null')]), $hasExtension];
        }

        if ($node instanceof Node\UnionType || $node instanceof Node\IntersectionType) {
            $members = [];
            $hasExtension = false;

            foreach ($node->types as $member) {
                [$resolved, $memberHasExtension] = $this->resolveDocumentedType($member);
                $members[] = $resolved;
                $hasExtension = $hasExtension || $memberHasExtension;
            }

            if ($members === []) {
                return [new \Amasiye\Ppphp\Semantic\Type\UnknownType(), false];
            }

            return [
                $node instanceof Node\UnionType ? new UnionType($members) : new IntersectionType($members),
                $hasExtension,
            ];
        }

        return [new \Amasiye\Ppphp\Semantic\Type\UnknownType(), false];
    }

    private function findGenericDeclarationAt(int $offset): ?GenericDeclaration
    {
        foreach ($this->context->parsedFile->extensionSyntax->genericDeclarations as $declaration) {
            if ($declaration->ownerNameSpan->start->offset === $offset) {
                return $declaration;
            }
        }

        return null;
    }

    private function addDocumentationConflict(Node $owner, string $message): void
    {
        $this->addDiagnostic(
            DiagnosticCode::GenericDocumentationConflictsWithNativeSyntax,
            $message,
            $this->createNodeSpan($owner),
        );
    }

    private function inspectNativeType(?Node $type, bool $static): void
    {
        if ($type === null) {
            return;
        }

        if ($type instanceof Node\Name) {
            $name = $type->toString();
            $span = $this->createNodeSpan($type);
            $hasAppliedReference = $this->hasAppliedReferenceAt($span->start->offset);
            $appliedReference = $this->findAppliedReferenceAt($span->start->offset);

            if (!$hasAppliedReference && $this->context->genericDeclarations->findType($name, $this->resolveNamespaceAt($span->start->offset)) !== null) {
                $this->addDiagnostic(
                    DiagnosticCode::GenericTypeArgumentsAreRequired,
                    sprintf('Generic type %s requires explicit type arguments in ++PHP source.', $name),
                    $span,
                );
            }

            $parameter = $this->findVisibleParameter($type);

            if ($static && $parameter !== null && $this->isClassParameter($parameter->ownerKey)) {
                $this->addDiagnostic(
                    DiagnosticCode::StaticMemberCannotUseClassTypeParameter,
                    sprintf('A static declaration cannot use class type parameter %s.', $name),
                    $span,
                );
            } elseif ($parameter === null && $this->context->genericDeclarations->containsParameterName($name)) {
                $this->addDiagnostic(
                    DiagnosticCode::UnknownTypeParameter,
                    sprintf('Type parameter %s is not visible in this declaration scope.', $name),
                    $span,
                );
            }

            if ($static && $appliedReference !== null) {
                foreach ($appliedReference->arguments as $argument) {
                    foreach ($this->collectAtomicTypes($this->types->parse($argument->text)) as $atomic) {
                        $argumentParameter = $this->context->genericDeclarations->findVisibleParameter(
                            $this->context->parsedFile->sourceFile,
                            $argument->span->start->offset,
                            $atomic->name,
                        );

                        if ($argumentParameter !== null && $this->isClassParameter($argumentParameter->ownerKey)) {
                            $this->addDiagnostic(
                                DiagnosticCode::StaticMemberCannotUseClassTypeParameter,
                                sprintf('A static declaration cannot use class type parameter %s.', $atomic->name),
                                $argument->span,
                            );
                        }
                    }
                }
            }

            return;
        }

        if ($type instanceof Node\NullableType) {
            $this->inspectNativeType($type->type, $static);
        } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $member) {
                $this->inspectNativeType($member, $static);
            }
        }
    }

    private function findVisibleParameter(Node\Name $name): ?\Amasiye\Ppphp\Semantic\Type\TypeParameter
    {
        return $this->context->genericDeclarations->findVisibleParameter(
            $this->context->parsedFile->sourceFile,
            max(0, $name->getStartFilePos()),
            $name->toString(),
        );
    }

    private function isClassParameter(string $ownerKey): bool
    {
        foreach ($this->context->genericDeclarations->entries as $entry) {
            if ($entry->key === $ownerKey) {
                return $entry->owner instanceof ClassSymbol;
            }
        }

        return false;
    }

    private function hasAppliedReferenceAt(int $offset): bool
    {
        return $this->findAppliedReferenceAt($offset) !== null;
    }

    private function findAppliedReferenceAt(int $offset): ?SourceGenericType
    {
        foreach ($this->context->parsedFile->extensionSyntax->genericTypes as $reference) {
            if ($reference->nameSpan->start->offset === $offset) {
                return $reference;
            }
        }

        return null;
    }

    /** @return list<AtomicType> */
    private function collectAtomicTypes(Type $type): array
    {
        if ($type instanceof AtomicType) {
            return [$type];
        }

        $atoms = [];

        foreach ($this->resolveMembers($type) as $member) {
            array_push($atoms, ...$this->collectAtomicTypes($member));
        }

        return $atoms;
    }

    /** @return list<Type> */
    private function resolveMembers(Type $type): array
    {
        return match (true) {
            $type instanceof UnionType, $type instanceof IntersectionType => $type->members,
            $type instanceof GenericType => $type->arguments,
            $type instanceof TypedArrayType => [$type->keyType, $type->valueType],
            default => [],
        };
    }

    private function resolveNamespaceAt(int $offset): string
    {
        $visible = $this->context->genericDeclarations->findVisibleDeclarations(
            $this->context->parsedFile->sourceFile,
            $offset,
        );
        $lastKey = array_key_last($visible);
        $entry = $lastKey === null ? null : $visible[$lastKey];

        return $entry === null ? '' : $entry->namespace;
    }

    private function findClass(string $name): ?ClassSymbol
    {
        $exact = $this->context->symbols->findClass($name);

        if ($exact !== null) {
            return $exact;
        }

        $matches = array_values(array_filter(
            $this->context->symbols->classes,
            static fn (ClassSymbol $class): bool => strcasecmp(
                TypeName::resolveShort($class->fullyQualifiedName),
                ltrim($name, '\\'),
            ) === 0,
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function findContainingStaticMethod(int $offset): ?MethodSymbol
    {
        foreach ($this->context->symbols->classes as $class) {
            if (Path::buildComparisonKey($class->sourceFile->path) !== Path::buildComparisonKey($this->context->parsedFile->sourceFile->path)) {
                continue;
            }

            foreach ($class->methods as $method) {
                if ($method->static && $offset >= $method->declarationSpan->start->offset && $offset < $method->declarationSpan->end->offset) {
                    return $method;
                }
            }
        }

        return null;
    }

    private function createNodeSpan(Node $node): Span
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        return $this->context->parsedFile->sourceFile->createSpan(
            max(0, $start),
            $start < 0 || $end < $start ? max(0, $start) : $end + 1,
        );
    }

    /** @param list<DiagnosticLabel> $related */
    private function addDiagnostic(
        DiagnosticCode $code,
        string $message,
        Span $span,
        array $related = [],
    ): void
    {
        $key = $code->value . ':' . $span->start->offset . ':' . $message;

        if (isset($this->reported[$key])) {
            return;
        }

        $this->reported[$key] = true;
        $this->context->model->diagnostics->add(new Diagnostic(
            $code,
            $message,
            new DiagnosticLabel($span, $message),
            $related,
        ));
    }
}
