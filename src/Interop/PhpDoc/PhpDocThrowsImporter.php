<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\PhpDoc;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Semantic\Effect\ErrorOccurrence;
use Amasiye\Ppphp\Semantic\Effect\ErrorSet;
use Amasiye\Ppphp\Semantic\SourceNameResolver;

final readonly class PhpDocThrowsImporter
{
    public function __construct(private SourceNameResolver $names = new SourceNameResolver()) {}

    /** @param list<PhpDocThrowsTag> $tags */
    public function import(ParsedFile $file, array $tags): ErrorSet
    {
        $errors = new ErrorSet();

        foreach ($tags as $tag) {
            $cursor = 0;

            foreach (explode('|', $tag->typeExpression) as $part) {
                $type = trim($part);
                $relative = strpos($tag->typeExpression, $type, $cursor);

                if ($relative === false) {
                    continue;
                }

                $cursor = $relative + strlen($type);

                if (strtolower($type) === 'void') {
                    continue;
                }

                $span = $file->sourceFile->createSpan(
                    $tag->typeSpan->start->offset + $relative,
                    $tag->typeSpan->start->offset + $relative + strlen($type),
                );
                $errors->add(new ErrorOccurrence(
                    $this->names->resolve($file, $type, $span->start->offset),
                    $span,
                    $tag->documentSpan,
                ));
            }
        }

        return $errors;
    }
}
