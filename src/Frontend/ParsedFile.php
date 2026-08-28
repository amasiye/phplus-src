<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Frontend\Enumerations\ParseMode;
use Amasiye\Phplus\Source\SourceFile;
use PhpParser\Node\Stmt;
use PhpParser\Token;

final readonly class ParsedFile
{
    /**
     * @param list<Stmt> $statements
     * @param list<Token> $tokens
     */
    public function __construct(
        public SourceFile $sourceFile,
        public ParseMode $mode,
        public array $statements,
        public array $tokens,
    ) {}
}
