<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Service\Plane\PlaneApiException;
use App\Service\Plane\PlaneClient;
use App\Service\Plane\PlaneStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TimeEntryPlaneController extends Controller
{
    /**
     * Move the Plane work item of the time entry's task to review
     *
     * Moves the work item to "In Review", or "Ready for Test" if the Plane project has no "In Review" state.
     * The task has to be named after the work item, f.e. "[KARA-47] Title".
     *
     * @operationId moveTimeEntryTaskToPlaneReview
     *
     * @response array{data: array{identifier: string, outcome: 'moved'|'already_in_state'|'work_item_closed'|'state_not_found'|'work_item_not_found', state: string|null}}
     *
     * @throws AuthorizationException
     */
    public function moveToReview(Organization $organization, TimeEntry $timeEntry, PlaneClient $planeClient, PlaneStatusService $planeStatusService): JsonResponse
    {
        if ($timeEntry->member_id === $this->member($organization)->getKey()) {
            $this->checkPermission($organization, 'time-entries:update:own');
        } else {
            $this->checkPermission($organization, 'time-entries:update:all');
        }
        if ($timeEntry->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('Time entry does not belong to organization');
        }

        if (! $planeClient->isConfigured()) {
            return response()->json(['message' => 'The Plane integration is not configured.'], 503);
        }

        /** @var Task|null $task */
        $task = $timeEntry->task_id !== null ? Task::query()->find($timeEntry->task_id) : null;
        $identifier = $task !== null ? PlaneStatusService::workItemIdentifierFromTaskName($task->name) : null;
        if ($identifier === null) {
            return response()->json(['message' => 'This time entry has no task linked to a Plane work item.'], 422);
        }

        try {
            $result = $planeStatusService->moveToReview($identifier);
        } catch (PlaneApiException $exception) {
            Log::warning('Plane: could not move work item to review', [
                'identifier' => $identifier,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Plane could not be reached. Please try again later.'], 502);
        }

        return response()->json([
            'data' => [
                'identifier' => $identifier,
                'outcome' => $result->outcome->value,
                'state' => $result->stateName,
            ],
        ]);
    }
}
