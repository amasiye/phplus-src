<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Frontend\Ast\ExtensionSyntaxIndex;
use Amasiye\Phplus\Frontend\Enumerations\ParseMode;
use Amasiye\Phplus\Frontend\Normalization\NormalizationPlan;
use Amasiye\Phplus\Frontend\Normalization\NormalizedSource;
use Amasiye\Phplus\Frontend\Normalization\SourceMap;
use Amasiye\Phplus\Frontend\Token\TokenStream;
use Amasiye\Phplus\Source\SourceFile;
use PhpParser\Node\Stmt;
use PhpParser\Token as PhpParserToken;

final readonly class ParsedFile
{
    /**
     * @param list<Stmt> $statements
     * @param list<PhpParserToken> $phpTokens
     */
    public function __construct(
        public SourceFile $sourceFile,
        public ParseMode $mode,
        public TokenStream $tokens,
        public ExtensionSyntaxIndex $extensionSyntax,
        public NormalizationPlan $normalizationPlan,
        public NormalizedSource $normalizedSource,
        public SourceMap $sourceMap,
        public array $statements,
        public array $phpTokens,
    ) {}
}
