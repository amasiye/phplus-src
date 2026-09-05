<?php

declare(strict_types=1);

namespace Tests\Support\FrameworkIntegrationSpike;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/** Specimen capability experiment, NOT a complete PHP compatibility validator or signature package. */
final readonly class PlatformProfile
{
    /** Reviewed specimen capabilities, deliberately no wildcard/future-version fallback. */
    private const array CAPABILITIES = [
        '8.4' => ['pipe' => false, 'array_first' => false],
        '8.5' => ['pipe' => true, 'array_first' => true],
    ];

    public function __construct(
        public string $host,
        public string $syntax,
        public string $signatures,
        public string $emission,
        public string $runtime,
    ) {
        foreach ([$host, $syntax, $signatures, $emission, $runtime] as $version) {
            if (!isset(self::CAPABILITIES[$version])) {
                throw new \InvalidArgumentException('SPIKE_UNREVIEWED_PLATFORM: ' . $version);
            }
        }
    }

    /** Checks ONLY the pipe and array_first specimens; unknown APIs are not claimed as validated. */
    public function validateSpecimen(string $source): void
    {
        try {
            $nodes = (new ParserFactory())->createForVersion(PhpVersion::fromString($this->syntax))->parse($source) ?? [];
        } catch (\PhpParser\Error $error) {
            throw new \DomainException('SPIKE_PARSE: ' . $error->getMessage(), previous: $error);
        }
        $finder = new NodeFinder();
        if ($finder->findFirstInstanceOf($nodes, Node\Expr\BinaryOp\Pipe::class) !== null) {
            if (!self::CAPABILITIES[$this->syntax]['pipe']) throw new \DomainException('SPIKE_SYNTAX_REQUIRES_8_5');
            if (!self::CAPABILITIES[$this->emission]['pipe']) throw new \DomainException('SPIKE_NATIVE_EMISSION_REQUIRES_8_5');
            if (!self::CAPABILITIES[$this->runtime]['pipe']) throw new \DomainException('SPIKE_NATIVE_RUNTIME_REQUIRES_8_5');
        }
        foreach ($finder->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Node\Name && strtolower($call->name->toString()) === 'array_first') {
                if (!self::CAPABILITIES[$this->signatures]['array_first']) throw new \DomainException('SPIKE_API_REQUIRES_8_5_SIGNATURES');
                if (!self::CAPABILITIES[$this->runtime]['array_first']) throw new \DomainException('SPIKE_API_REQUIRES_8_5_RUNTIME');
            }
        }
    }

    /**
     * Lower-bound/extension specimen, not a substitute for Composer's full constraint solver.
     * @param list<string> $requiredExtensions
     * @param list<string> $availableExtensions
     */
    public function validateRuntimeRequirements(string $minimum, array $requiredExtensions, array $availableExtensions): void
    {
        if (version_compare($this->runtime, $minimum, '<')) {
            throw new \DomainException('SPIKE_DEPENDENCY_RUNTIME_TOO_OLD');
        }
        if (array_diff($requiredExtensions, $availableExtensions) !== []) {
            throw new \DomainException('SPIKE_REQUIRED_EXTENSION_MISSING');
        }
    }

    /** @param array<string, string> $extensions versioned runtime extensions */
    public function calculateIdentity(string $compiler, string $parser, string $signatureHash, string $lockHash, array $extensions): string
    {
        ksort($extensions);
        return hash('sha256', json_encode([
            'contract' => 'fi0-specimens-1',
            'profile' => $this,
            'compiler' => $compiler,
            'parser' => $parser,
            'signatures' => $signatureHash,
            'lock' => $lockHash,
            'extensions' => $extensions,
        ], JSON_THROW_ON_ERROR));
    }
}
