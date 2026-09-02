<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Effect;

final readonly class EffectCompatibility
{
    public function filterIncompatibleErrors(
        CallableErrorContract $child,
        CallableErrorContract $parent,
        ThrowableHierarchy $hierarchy,
    ): ErrorSet {
        $incompatible = new ErrorSet();

        foreach ($child->filterCheckedErrors($hierarchy) as $error) {
            if (!$parent->covers($error, $hierarchy)) {
                $incompatible->add($error);
            }
        }

        return $incompatible;
    }
}
