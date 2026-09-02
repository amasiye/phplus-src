<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Php\Intrinsic;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Semantic\Call\CallableContract;
use Atatusoft\Ppphp\Semantic\Call\CallableKind;
use Atatusoft\Ppphp\Semantic\Call\CallableOrigin;
use Atatusoft\Ppphp\Semantic\Effect\CallableErrorContract;
use Atatusoft\Ppphp\Semantic\Symbol\ParameterSymbol;
use Atatusoft\Ppphp\Semantic\Type\CompositeTypeParser;
use Atatusoft\Ppphp\Semantic\Type\NamedType;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Source\Span;

final class IntrinsicFunctionRepository
{
    /** @var list<string> */
    public const array FUNCTION_NAMES = [
        'array_filter',
        'array_values',
        'count',
        'is_array',
        'is_bool',
        'is_callable',
        'is_float',
        'is_int',
        'is_null',
        'is_object',
        'is_string',
        'strlen',
    ];

    /** @var array<string, CallableContract> */
    private array $contracts;

    private readonly Span $span;

    public function __construct(private readonly CompositeTypeParser $types = new CompositeTypeParser())
    {
        $source = new SourceFile(
            '/__ppphp_intrinsics__.stub.php',
            '<PHP intrinsic>',
            FileKind::Stub,
            '',
            DeclarationOrigin::IntrinsicOverride,
        );
        $this->span = $source->createSpan(0, 0);
        $this->contracts = $this->buildContracts();
    }

    public function find(string $name): ?CallableContract
    {
        return $this->contracts[strtolower(ltrim($name, '\\'))] ?? null;
    }

    /** @return array<string, CallableContract> */
    private function buildContracts(): array
    {
        $contracts = [];
        $this->add($contracts, 'strlen', [['string', '$string']], 'int');
        $this->add($contracts, 'count', [['array|Countable', '$value'], ['int', '$mode', true]], 'int');

        foreach (['is_null', 'is_int', 'is_string', 'is_bool', 'is_float', 'is_array', 'is_object', 'is_callable'] as $name) {
            $this->add($contracts, $name, [['mixed', '$value']], 'bool');
        }

        $this->add($contracts, 'array_filter', [['array', '$array'], ['?callable', '$callback', true], ['int', '$mode', true]], 'array');
        $this->add($contracts, 'array_values', [['array', '$array']], 'array');

        return $contracts;
    }

    /**
     * @param array<string, CallableContract> $contracts
     * @param list<array{0: string, 1: string, 2?: bool}> $parameters
     */
    private function add(array &$contracts, string $name, array $parameters, string $returnType): void
    {
        $parameterSymbols = [];

        foreach ($parameters as $position => $parameter) {
            [$type, $parameterName] = $parameter;
            $parameterSymbols[] = new ParameterSymbol(
                $parameterName,
                new NamedType($this->types->parse($type)),
                false,
                false,
                false,
                $this->span,
                $this->span,
                null,
                $position,
                $parameter[2] ?? false,
            );
        }

        $contracts[$name] = new CallableContract(
            CallableKind::Intrinsic,
            $name,
            null,
            CallableOrigin::IntrinsicOverride,
            $parameterSymbols,
            $this->types->parse($returnType),
            null,
            [],
            CallableErrorContract::createEmpty($this->span),
            'public',
            true,
            false,
            null,
            null,
        );
    }
}
