<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Effect;

use Amasiye\Ppphp\Frontend\Ast\ThrowsClause;
use Amasiye\Ppphp\Source\Span;

final readonly class CallableErrorContract
{
    public function __construct(
        public ErrorSet $declaredErrors,
        public Span $ownerSpan,
        public ?ThrowsClause $nativeClause = null,
        public ?Span $phpDocSpan = null,
    ) {}

    public static function createEmpty(Span $ownerSpan): self
    {
        return new self(new ErrorSet(), $ownerSpan);
    }

    public function filterCheckedErrors(ThrowableHierarchy $hierarchy): ErrorSet
    {
        $checked = new ErrorSet();

        foreach ($this->declaredErrors as $occurrence) {
            if ($hierarchy->classify($occurrence->canonicalType) === Enumerations\ThrowableKind::Checked) {
                $checked->add($occurrence);
            }
        }

        return $checked;
    }

    public function covers(ErrorOccurrence $occurrence, ThrowableHierarchy $hierarchy): bool
    {
        foreach ($this->filterCheckedErrors($hierarchy) as $declared) {
            if ($hierarchy->matchesSubtype($occurrence->canonicalType, $declared->canonicalType)) {
                return true;
            }
        }

        return false;
    }
}
