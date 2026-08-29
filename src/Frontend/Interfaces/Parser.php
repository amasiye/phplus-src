<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Interfaces;

use Amasiye\Ppphp\Frontend\Enumerations\ParseMode;
use Amasiye\Ppphp\Frontend\ParseResult;
use Amasiye\Ppphp\Source\SourceFile;

interface Parser
{
    public function parse(
        SourceFile $sourceFile,
        ParseMode $mode = ParseMode::PlusPlusPhp,
    ): ParseResult;
}
