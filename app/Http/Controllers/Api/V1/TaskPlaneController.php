<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\Task;
use App\Service\Plane\PlaneApiException;
use App\Service\Plane\PlaneClient;
use App\Service\Plane\PlaneStatusService;
use App\Service\Plane\PlaneWorkItemDetailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TaskPlaneController extends Controller
{
    /**
     * Get the Plane work item of a task
     *
     * Read-only details of the Plane work item the task is named after, f.e. "[KARA-48] Title".
     * The description is sanitized HTML; images are replaced by a placeholder.
     *
     * @operationId getTaskPlaneWorkItem
     *
     * @response array{data: array{identifier: string, name: string, url: string, state: array{name: string, color: string, group: string}|null, priority: string, assignees: array<int, array{display_name: string}>, labels: array<int, array{name: string, color: string}>, start_date: string|null, target_date: string|null, description_html: string}}
     *
     * @throws AuthorizationException
     */
    public function show(Organization $organization, Task $task, PlaneClient $planeClient, PlaneWorkItemDetailService $detailService): JsonResponse
    {
        $this->checkPermission($organization, 'tasks:view');
        if ($task->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('Task does not belong to organization');
        }
        if (! $this->hasPermission($organization, 'tasks:view:all')) {
            $isVisible = Task::query()
                ->whereKey($task->getKey())
                ->visibleByEmployee($this->user())
                ->exists();
            if (! $isVisible) {
                throw new AuthorizationException('You do not have permission to view this task.');
            }
        }

        if (! $planeClient->isEnabledForOrganization($organization->getKey())) {
            return response()->json(['message' => 'The Plane integration is not enabled for this organization.'], 503);
        }

        $identifier = PlaneStatusService::workItemIdentifierFromTaskName($task->name);
        if ($identifier === null) {
            return response()->json(['message' => 'This task is not linked to a Plane work item.'], 422);
        }

        try {
            $detail = $detailService->detail($identifier);
        } catch (PlaneApiException $exception) {
            Log::warning('Plane: could not load work item', [
                'identifier' => $identifier,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Plane could not be reached. Please try again later.'], 502);
        }

        if ($detail === null) {
            return response()->json(['message' => 'This work item was not found in Plane.'], 404);
        }

        return response()->json(['data' => $detail]);
    }
}
