<?php

declare(strict_types=1);

namespace Example\Mixed\Http;

use Example\Mixed\Domain\Box;
use Example\Mixed\Infrastructure\LegacyUnavailable;
use Example\Mixed\Presentation\LegacyPresenter;
use Example\Mixed\Service\PersonService;

final readonly class LegacyController
{
    public function __construct(
        private PersonService $service,
        private LegacyPresenter $presenter,
    ) {
    }

    /** @throws LegacyUnavailable */
    public function handle(string|int $id): string
    {
        return $this->presenter->present(new Box($this->service->load((string) $id)))
            . '|' . $this->summarizeCollections();
    }

    private function summarizeCollections(): string
    {
        $tags = $this->service->tags();
        $scores = $this->service->scores();

        return $tags[0] . ':' . $scores['quality'];
    }
}
