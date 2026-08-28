<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Interfaces;

use Amasiye\Phplus\Frontend\Enumerations\ParseMode;
use Amasiye\Phplus\Frontend\ParseResult;
use Amasiye\Phplus\Source\SourceFile;

interface Parser
{
    public function parse(
        SourceFile $sourceFile,
        ParseMode $mode = ParseMode::Phplus,
    ): ParseResult;
}
