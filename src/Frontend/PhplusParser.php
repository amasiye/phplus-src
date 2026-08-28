<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Frontend\Enumerations\ParseMode;
use Amasiye\Phplus\Frontend\Interfaces\Parser;
use Amasiye\Phplus\Source\SourceFile;

final readonly class PhplusParser implements Parser
{
    public function __construct(private PhpParserAdapter $phpParserAdapter = new PhpParserAdapter()) {}

    public function parse(
        SourceFile $sourceFile,
        ParseMode $mode = ParseMode::Phplus,
    ): ParseResult {
        return $this->phpParserAdapter->parse($sourceFile, $mode);
    }
}
