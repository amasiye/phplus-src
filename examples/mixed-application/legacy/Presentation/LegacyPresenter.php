<?php

declare(strict_types=1);

namespace Example\Mixed\Presentation;

use Example\Mixed\Contracts\Named;
use Example\Mixed\Domain\Box;
use Example\Mixed\Domain\Person;

final class LegacyPresenter
{
    /** @param Box<Person> $box */
    public function present(Box $box): string
    {
        return $this->describe($box->value());
    }

    public function describe(\Stringable&Named $person): string
    {
        return $person->name() . ':' . (string) $person;
    }
}
