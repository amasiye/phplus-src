<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend;

use Atatusoft\Ppphp\Frontend\Ast\ExtensionSyntaxIndex;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\Normalization\NormalizationPlan;
use Atatusoft\Ppphp\Frontend\Normalization\NormalizedSource;
use Atatusoft\Ppphp\Frontend\Normalization\SourceMap;
use Atatusoft\Ppphp\Frontend\Token\TokenStream;
use Atatusoft\Ppphp\Source\SourceFile;
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
