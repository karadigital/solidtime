<?php

declare(strict_types=1);

namespace App\Service\Plane;

readonly class PlaneStatusResult
{
    public function __construct(
        public PlaneTransitionOutcome $outcome,
        public ?string $stateName = null,
    ) {}
}
