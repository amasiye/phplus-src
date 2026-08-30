<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\PhpDoc;

use Amasiye\Ppphp\Source\SourceFile;
use PhpParser\Comment\Doc;

final readonly class PhpDocReader
{
    /** @return list<PhpDocThrowsTag> */
    public function readThrows(?Doc $document, SourceFile $sourceFile): array
    {
        if ($document === null) {
            return [];
        }

        $text = $document->getText();
        $documentStart = $document->getStartFilePos();
        $documentSpan = $sourceFile->createSpan($documentStart, $document->getEndFilePos() + 1);
        $tags = [];
        $cursor = 0;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $tagOffset = strpos($line, '@throws');

            if ($tagOffset !== false) {
                $afterTag = substr($line, $tagOffset + strlen('@throws'));
                $leading = strlen($afterTag) - strlen(ltrim($afterTag));
                $content = ltrim($afterTag);
                $parts = preg_split('/\s+/', $content, 2) ?: [];
                $type = $parts[0] ?? '';

                if ($type !== '') {
                    $typeStart = $documentStart + $cursor + $tagOffset + strlen('@throws') + $leading;
                    $tags[] = new PhpDocThrowsTag(
                        $type,
                        $sourceFile->createSpan($typeStart, $typeStart + strlen($type)),
                        $documentSpan,
                        $parts[1] ?? '',
                    );
                }
            }

            $cursor += strlen($line);
            $cursor += str_starts_with(substr($text, $cursor), "\r\n") ? 2 : 1;
        }

        return $tags;
    }
}
