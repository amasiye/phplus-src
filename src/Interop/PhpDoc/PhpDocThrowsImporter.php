<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\PhpDoc;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Semantic\Effect\ErrorOccurrence;
use Atatusoft\Ppphp\Semantic\Effect\ErrorSet;
use Atatusoft\Ppphp\Semantic\SourceNameResolver;

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
