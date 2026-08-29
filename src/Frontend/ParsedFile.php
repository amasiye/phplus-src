<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend;

use Amasiye\Ppphp\Frontend\Ast\ExtensionSyntaxIndex;
use Amasiye\Ppphp\Frontend\Enumerations\ParseMode;
use Amasiye\Ppphp\Frontend\Normalization\NormalizationPlan;
use Amasiye\Ppphp\Frontend\Normalization\NormalizedSource;
use Amasiye\Ppphp\Frontend\Normalization\SourceMap;
use Amasiye\Ppphp\Frontend\Token\TokenStream;
use Amasiye\Ppphp\Source\SourceFile;
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
