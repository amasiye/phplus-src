<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Semantic\Symbol\MethodSymbol;
use Amasiye\Ppphp\Semantic\Symbol\PropertySymbol;
use Amasiye\Ppphp\Semantic\Symbol\SymbolTable;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

/** Resolves members and applies receiver-specific generic substitutions. */
final readonly class MemberTypeResolver
{
    public function __construct(private SymbolTable $symbols) {}

    public function resolveMethod(Type $receiver, string $name): MemberResolution
    {
        return $this->resolve($receiver, $name, true);
    }

    public function resolveProperty(Type $receiver, string $name): MemberResolution
    {
        return $this->resolve($receiver, $name, false);
    }

    public function resolveMethodReturnType(Type $receiver, string $name, bool $nullsafe = false): Type
    {
        $resolution = $this->resolveMethod($receiver, $name);

        if (!$resolution->complete) {
            return new UnknownType();
        }

        $types = [];

        foreach ($resolution->targets as $target) {
            $member = $target['member'];

            if ($member instanceof MethodSymbol && $member->returnType !== null) {
                $types[] = $this->resolveTargetType(
                    $member->returnType->semanticType,
                    $target['receiver'],
                    $target['substitutions'],
                    $target['calledReceiver'],
                );
            }
        }

        if ($nullsafe && $types !== []) {
            $types[] = new AtomicType('null');
        }

        return $this->combine($types);
    }

    public function resolvePropertyType(Type $receiver, string $name, bool $nullsafe = false): Type
    {
        $resolution = $this->resolveProperty($receiver, $name);

        if (!$resolution->complete) {
            return new UnknownType();
        }

        $types = [];

        foreach ($resolution->targets as $target) {
            $member = $target['member'];

            if ($member instanceof PropertySymbol && $member->type !== null) {
                $types[] = $this->resolveTargetType(
                    $member->type->semanticType,
                    $target['receiver'],
                    $target['substitutions'],
                    $target['calledReceiver'],
                );
            }
        }

        if ($nullsafe && $types !== []) {
            $types[] = new AtomicType('null');
        }

        return $this->combine($types);
    }

    /** @param list<Type> $types */
    public function combine(array $types): Type
    {
        $unique = [];

        foreach ($types as $type) {
            if (!$type->isUnknown) {
                $unique[$type->canonical] = $type;
            }
        }

        return match (count($unique)) {
            0 => new UnknownType(),
            1 => array_values($unique)[0],
            default => new UnionType(array_values($unique)),
        };
    }

    /** @param array<string, Type> $substitutions */
    public function resolveTargetType(
        Type $type,
        Type $receiver,
        array $substitutions,
        ?Type $calledReceiver = null,
    ): Type
    {
        return (new TypeSubstitution($substitutions))->substitute(
            $this->resolveContextualType($type, $receiver, $calledReceiver ?? $receiver),
        );
    }

    private function resolve(
        Type $type,
        string $name,
        bool $method,
        ?Type $calledReceiver = null,
    ): MemberResolution
    {
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $targets = [];
            $complete = $type instanceof UnionType;
            $resolvedMember = false;
            $deferred = false;
            $unresolved = [];

            foreach ($type->members as $member) {
                if ($member instanceof AtomicType && $member->canonical === 'null') {
                    continue;
                }

                $resolvedMember = true;
                $resolution = $this->resolve($member, $name, $method, $calledReceiver ?? $member);
                array_push($targets, ...$resolution->targets);
                array_push($unresolved, ...$resolution->unresolvedReceivers);
                $deferred = $deferred || in_array($resolution->status, [
                    MemberResolutionStatus::DeferredExternal,
                    MemberResolutionStatus::UnknownReceiver,
                ], true);
                $complete = $type instanceof UnionType
                    ? $complete && $resolution->complete
                    : $complete || $resolution->targets !== [];
            }

            $isComplete = $resolvedMember && $complete;

            return new MemberResolution(
                $targets,
                $isComplete,
                $isComplete
                    ? MemberResolutionStatus::Found
                    : ($deferred ? MemberResolutionStatus::DeferredExternal : MemberResolutionStatus::Missing),
                array_values(array_unique($unresolved)),
            );
        }

        if ($type instanceof TypeParameter) {
            return $type->bound === null
                ? new MemberResolution([], false, MemberResolutionStatus::UnknownReceiver, [$type->renderPhpDoc()])
                : $this->resolve($type->bound, $name, $method, $calledReceiver ?? $type);
        }

        if (!$type instanceof AtomicType && !$type instanceof GenericType) {
            return new MemberResolution([], false, MemberResolutionStatus::UnknownReceiver, [$type->renderPhpDoc()]);
        }

        $className = $type instanceof GenericType ? $type->base->name : $type->name;

        if ($this->symbols->findClass($className) === null) {
            return new MemberResolution([], false, MemberResolutionStatus::DeferredExternal, [$type->renderPhpDoc()]);
        }

        $visited = [];
        $target = $this->findInHierarchy(
            $type,
            $calledReceiver ?? $type,
            $name,
            $method,
            $visited,
        );

        return new MemberResolution(
            $target === null ? [] : [$target],
            $target !== null,
            $target === null
                ? ($this->hasDeferredHierarchy($type) ? MemberResolutionStatus::DeferredExternal : MemberResolutionStatus::Missing)
                : MemberResolutionStatus::Found,
            $target === null ? [$type->renderPhpDoc()] : [],
        );
    }

    /**
     * @param array<string, true> $visited
     * @return array{
     *     member: MethodSymbol|PropertySymbol,
     *     owner: ClassSymbol,
     *     receiver: Type,
     *     calledReceiver: Type,
     *     substitutions: array<string, Type>
     * }|null
     */
    private function findInHierarchy(
        AtomicType|GenericType $receiver,
        Type $calledReceiver,
        string $name,
        bool $method,
        array &$visited,
    ): ?array {
        $className = $receiver instanceof GenericType ? $receiver->base->name : $receiver->name;
        $key = strtolower(ltrim($className, '\\')) . '<' . $receiver->canonical . '>';

        if (isset($visited[$key])) {
            return null;
        }

        $visited[$key] = true;
        $class = $this->symbols->findClass($className);

        if ($class === null) {
            return null;
        }

        $substitutions = $this->resolveClassSubstitutions($class, $receiver);
        $member = $method ? $class->findMethod($name) : $class->findProperty($name);

        if ($member !== null) {
            return [
                'member' => $member,
                'owner' => $class,
                'receiver' => $receiver,
                'calledReceiver' => $calledReceiver,
                'substitutions' => $substitutions,
            ];
        }

        foreach ($this->resolveRelatedTypes($class) as $related) {
            $related = (new TypeSubstitution($substitutions))->substitute($related);

            if (!$related instanceof AtomicType && !$related instanceof GenericType) {
                continue;
            }

            $target = $this->findInHierarchy(
                $related,
                $calledReceiver,
                $name,
                $method,
                $visited,
            );

            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    /** @return array<string, Type> */
    private function resolveClassSubstitutions(ClassSymbol $class, Type $receiver): array
    {
        if (!$receiver instanceof GenericType || $class->genericDeclaration === null) {
            return [];
        }

        $substitutions = [];

        foreach ($class->genericDeclaration->parameters as $index => $parameter) {
            $argument = $receiver->arguments[$index] ?? null;

            if ($argument !== null) {
                $substitutions[$parameter->canonical] = $argument;
            }
        }

        return $substitutions;
    }

    /** @param array<string, true> $visited */
    private function hasDeferredHierarchy(AtomicType|GenericType $receiver, array &$visited = []): bool
    {
        $className = $receiver instanceof GenericType ? $receiver->base->name : $receiver->name;
        $class = $this->symbols->findClass($className);

        if ($class === null) {
            return true;
        }

        $key = strtolower($class->fullyQualifiedName);

        if (isset($visited[$key])) {
            return false;
        }

        $visited[$key] = true;

        foreach ($this->resolveRelatedTypes($class) as $related) {
            if (!$related instanceof AtomicType && !$related instanceof GenericType) {
                continue;
            }

            if ($this->hasDeferredHierarchy($related, $visited)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Type> */
    private function resolveRelatedTypes(ClassSymbol $class): array
    {
        $related = [];

        foreach ($class->traitTypes as $type) {
            $related[] = $type->semanticType;
        }

        foreach ($class->interfaceTypes as $type) {
            $related[] = $type->semanticType;
        }

        if ($class->parentType !== null) {
            $related[] = $class->parentType->semanticType;
        }

        if ($related !== []) {
            return $related;
        }

        foreach ([...$class->traits, ...$class->interfaces, ...($class->parent === null ? [] : [$class->parent])] as $name) {
            $related[] = new AtomicType($name);
        }

        return $related;
    }

    private function resolveContextualType(
        Type $type,
        Type $receiver,
        Type $calledReceiver,
    ): Type
    {
        if ($type instanceof AtomicType && $type->canonical === 'self') {
            return $receiver;
        }

        if ($type instanceof AtomicType && $type->canonical === 'static') {
            return $calledReceiver;
        }

        if ($type instanceof GenericType) {
            return new GenericType(
                $type->base,
                array_map(
                    fn (Type $argument): Type => $this->resolveContextualType(
                        $argument,
                        $receiver,
                        $calledReceiver,
                    ),
                    $type->arguments,
                ),
            );
        }

        if ($type instanceof TypedArrayType) {
            return new TypedArrayType(
                $this->resolveContextualType($type->keyType, $receiver, $calledReceiver),
                $this->resolveContextualType($type->valueType, $receiver, $calledReceiver),
                $type->isList,
            );
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $members = array_map(
                fn (Type $member): Type => $this->resolveContextualType(
                    $member,
                    $receiver,
                    $calledReceiver,
                ),
                $type->members,
            );

            return $type instanceof UnionType ? new UnionType($members) : new IntersectionType($members);
        }

        return $type;
    }
}
