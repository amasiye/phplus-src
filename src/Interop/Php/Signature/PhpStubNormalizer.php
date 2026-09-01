<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Php\Signature;

use Amasiye\Ppphp\Analysis\DeclarationContextEmitter;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * @phpstan-type MemberSymbol array{availability: string|null, kind: string, name: string}
 * @phpstan-type StubSymbol array{availability: string|null, kind: string, name: string, members?: list<MemberSymbol>}
 */
final readonly class PhpStubNormalizer
{
    /** @var array<string, string> */
    public const array DIRECTIVE_DISPOSITIONS = [
        'alias' => 'consumed',
        'compile-time-eval' => 'preserved',
        'cvalue' => 'preserved',
        'deprecated' => 'preserved',
        'frameless-function' => 'ignored-code-generation-only',
        'generate-class-entries' => 'ignored-code-generation-only',
        'generate-function-entries' => 'ignored-code-generation-only',
        'generate-legacy-arginfo' => 'ignored-code-generation-only',
        'genstubs-expose-comment-block' => 'ignored-code-generation-only',
        'implementation-alias' => 'consumed',
        'internal' => 'preserved',
        'link' => 'preserved',
        'no-file-cache' => 'ignored-code-generation-only',
        'no-verify' => 'ignored-code-generation-only',
        'not-serializable' => 'preserved',
        'param' => 'consumed',
        'prefer-ref' => 'preserved',
        'readonly' => 'preserved',
        'refcount' => 'ignored-runtime-memory-only',
        'return' => 'consumed',
        'see' => 'preserved',
        'since' => 'preserved',
        'strict-properties' => 'preserved',
        'tentative-return-type' => 'consumed',
        'todo' => 'preserved',
        'undocumentable' => 'preserved',
        'var' => 'consumed',
        'virtual' => 'preserved',
    ];

    public function __construct(
        private ParserFactory $parsers = new ParserFactory(),
        private DeclarationContextEmitter $emitter = new DeclarationContextEmitter(),
    ) {}

    public function normalize(string $relativePath, string $contents): PhpStubNormalization
    {
        $directives = $this->auditDirectives($contents, $relativePath);
        [$php, $conditions] = $this->preprocess($contents, $relativePath);

        try {
            $statements = $this->parsers
                ->createForVersion(PhpVersion::fromString('8.4'))
                ->parse($php);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf(
                'Could not parse upstream stub "%s": %s',
                $relativePath,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if ($statements === null) {
            throw new \RuntimeException(sprintf('Upstream stub "%s" produced no syntax tree.', $relativePath));
        }

        $sourceFile = new SourceFile(
            '/php-src/' . ltrim(str_replace('\\', '/', $relativePath), '/'),
            $relativePath,
            FileKind::Stub,
            $php,
        );
        $normalizedSource = $this->emitter->emit($sourceFile, $php)->contents;
        $counts = [
            'functions' => 0,
            'classLikes' => 0,
            'methods' => 0,
            'properties' => 0,
            'constants' => 0,
            'aliases' => 0,
        ];
        /** @var list<StubSymbol> $symbols */
        $symbols = [];
        /** @var list<array{declaration: string, target: string, kind: string}> $aliases */
        $aliases = [];
        $this->collect(array_values($statements), '', $conditions, $counts, $symbols, $aliases);
        $counts['aliases'] = count($aliases);

        return new PhpStubNormalization(
            $normalizedSource,
            $counts,
            $symbols,
            $aliases,
            $directives,
        );
    }

    /** @return array<string, array{count: int, disposition: string}> */
    private function auditDirectives(string $contents, string $relativePath): array
    {
        preg_match_all('/(?<![A-Za-z0-9])@([A-Za-z][A-Za-z0-9_-]*)/', $contents, $matches);
        $counts = array_count_values($matches[1]);
        ksort($counts, SORT_STRING);
        $audit = [];

        foreach ($counts as $name => $count) {
            $disposition = self::DIRECTIVE_DISPOSITIONS[$name] ?? null;

            if ($disposition === null) {
                throw new \RuntimeException(sprintf(
                    'Upstream stub "%s" uses unsupported directive @%s.',
                    $relativePath,
                    $name,
                ));
            }

            $audit[$name] = ['count' => $count, 'disposition' => $disposition];
        }

        return $audit;
    }

    /** @return array{string, array<int, string|null>} */
    private function preprocess(string $contents, string $relativePath): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $contents);

        if ($lines === false) {
            throw new \RuntimeException(sprintf('Could not read lines from upstream stub "%s".', $relativePath));
        }

        /** @var list<array{condition: string, else: bool}> $stack */
        $stack = [];
        $conditions = [];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;

            if (preg_match('/^\s*#\s*([A-Za-z]+)(?:\s+(.*?))?\s*$/', $line, $match) !== 1) {
                $conditions[$lineNumber] = $this->combinedCondition($stack);
                continue;
            }

            $directive = strtolower($match[1]);
            $argument = trim($match[2] ?? '');
            $lines[$index] = '';

            if (in_array($directive, ['if', 'ifdef', 'ifndef'], true)) {
                if ($argument === '') {
                    throw new \RuntimeException(sprintf('Empty #%s in upstream stub "%s".', $directive, $relativePath));
                }

                $condition = match ($directive) {
                    'if' => preg_replace('/\s+/', ' ', $argument),
                    'ifdef' => sprintf('defined(%s)', $argument),
                    'ifndef' => sprintf('!defined(%s)', $argument),
                };
                $stack[] = ['condition' => (string) $condition, 'else' => false];
                continue;
            }

            if ($directive === 'else') {
                $position = array_key_last($stack);

                if ($position === null || $stack[$position]['else']) {
                    throw new \RuntimeException(sprintf('Unbalanced #else in upstream stub "%s".', $relativePath));
                }

                $stack[$position] = [
                    'condition' => sprintf('!(%s)', $stack[$position]['condition']),
                    'else' => true,
                ];
                continue;
            }

            if ($directive === 'endif') {
                if (array_pop($stack) === null) {
                    throw new \RuntimeException(sprintf('Unbalanced #endif in upstream stub "%s".', $relativePath));
                }

                continue;
            }

            throw new \RuntimeException(sprintf(
                'Upstream stub "%s" uses unsupported preprocessor directive #%s.',
                $relativePath,
                $directive,
            ));
        }

        if ($stack !== []) {
            throw new \RuntimeException(sprintf('Unclosed conditional in upstream stub "%s".', $relativePath));
        }

        return [implode("\n", $lines), $conditions];
    }

    /** @param list<array{condition: string, else: bool}> $stack */
    private function combinedCondition(array $stack): ?string
    {
        if ($stack === []) {
            return null;
        }

        return implode(' && ', array_map(
            static fn (array $entry): string => '(' . $entry['condition'] . ')',
            $stack,
        ));
    }

    /**
     * @param list<Node\Stmt> $statements
     * @param array<int, string|null> $conditions
     * @param array{functions: int, classLikes: int, methods: int, properties: int, constants: int, aliases: int} $counts
     * @param list<StubSymbol> $symbols
     * @param-out list<StubSymbol> $symbols
     * @param list<array{declaration: string, target: string, kind: string}> $aliases
     * @param-out list<array{declaration: string, target: string, kind: string}> $aliases
     */
    private function collect(
        array $statements,
        string $namespace,
        array $conditions,
        array &$counts,
        array &$symbols,
        array &$aliases,
    ): void {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                $this->collect(
                    array_values($statement->stmts),
                    $statement->name?->toString() ?? '',
                    $conditions,
                    $counts,
                    $symbols,
                    $aliases,
                );
                continue;
            }

            if ($statement instanceof Node\Stmt\Function_) {
                $name = $this->qualify($namespace, $statement->name->toString());
                $counts['functions']++;
                $symbols[] = $this->symbol('function', $name, $statement, $conditions);
                $this->collectAliases($name, $statement, $aliases);
                continue;
            }

            if ($statement instanceof Node\Stmt\Const_) {
                foreach ($statement->consts as $constant) {
                    $name = $this->qualify($namespace, $constant->name->toString());
                    $counts['constants']++;
                    $symbols[] = $this->symbol('constant', $name, $constant, $conditions);
                }
                continue;
            }

            if (!$statement instanceof Node\Stmt\ClassLike || $statement->name === null) {
                continue;
            }

            $name = $this->qualify($namespace, $statement->name->toString());
            $counts['classLikes']++;
            $kind = match (true) {
                $statement instanceof Node\Stmt\Interface_ => 'interface',
                $statement instanceof Node\Stmt\Trait_ => 'trait',
                $statement instanceof Node\Stmt\Enum_ => 'enum',
                default => 'class',
            };
            $entry = $this->symbol($kind, $name, $statement, $conditions);
            /** @var list<MemberSymbol> $members */
            $members = [];

            foreach ($statement->stmts as $member) {
                if ($member instanceof Node\Stmt\ClassMethod) {
                    $counts['methods']++;
                    $memberName = $name . '::' . $member->name->toString();
                    $members[] = $this->symbol('method', $memberName, $member, $conditions);
                    $this->collectAliases($memberName, $member, $aliases);
                    continue;
                }

                if ($member instanceof Node\Stmt\Property) {
                    $counts['properties'] += count($member->props);

                    foreach ($member->props as $property) {
                        $members[] = $this->symbol(
                            'property',
                            $name . '::$' . $property->name->toString(),
                            $property,
                            $conditions,
                        );
                    }
                    continue;
                }

                if ($member instanceof Node\Stmt\ClassConst) {
                    $counts['constants'] += count($member->consts);

                    foreach ($member->consts as $constant) {
                        $members[] = $this->symbol(
                            'class-constant',
                            $name . '::' . $constant->name->toString(),
                            $constant,
                            $conditions,
                        );
                    }
                    continue;
                }

                if ($member instanceof Node\Stmt\EnumCase) {
                    $counts['constants']++;
                    $members[] = $this->symbol(
                        'enum-case',
                        $name . '::' . $member->name->toString(),
                        $member,
                        $conditions,
                    );
                }
            }

            $entry['members'] = $members;
            $symbols[] = $entry;
        }
    }

    /**
     * @param array<int, string|null> $conditions
     * @return MemberSymbol
     */
    private function symbol(string $kind, string $name, Node $node, array $conditions): array
    {
        return [
            'availability' => $conditions[$node->getStartLine()] ?? null,
            'kind' => $kind,
            'name' => $name,
        ];
    }

    /** @param list<array{declaration: string, target: string, kind: string}> $aliases */
    private function collectAliases(string $name, Node $node, array &$aliases): void
    {
        $comment = $node->getDocComment()?->getText();

        if ($comment === null) {
            return;
        }

        preg_match_all('/@(alias|implementation-alias)\s+([^\s*]+)/', $comment, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $aliases[] = [
                'declaration' => $name,
                'target' => $match[2],
                'kind' => $match[1],
            ];
        }
    }

    private function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }
}
