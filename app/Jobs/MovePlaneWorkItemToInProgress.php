<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Service\Plane\PlaneStatusService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves a Plane work item to "In Progress" after a timer was started on its task.
 * Dispatched after the response, so the time tracker is never slowed down or broken by Plane.
 */
class MovePlaneWorkItemToInProgress
{
    use Dispatchable;

    public function __construct(public readonly string $identifier) {}

    public function handle(PlaneStatusService $planeStatusService): void
    {
        try {
            $result = $planeStatusService->moveToInProgress($this->identifier);
            Log::info('Plane: work item status sync on timer start', [
                'identifier' => $this->identifier,
                'outcome' => $result->outcome->value,
                'state' => $result->stateName,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Plane: could not move work item to In Progress', [
                'identifier' => $this->identifier,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
