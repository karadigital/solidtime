<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\MovePlaneWorkItemToInProgress;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Service\Plane\PlaneClient;
use App\Service\Plane\PlaneStatusService;

class TimeEntryPlaneObserver
{
    public function __construct(private readonly PlaneClient $planeClient) {}

    /**
     * A timer was started on a task that belongs to a Plane work item: move the work item to "In Progress".
     */
    public function created(TimeEntry $timeEntry): void
    {
        $this->moveWorkItemToInProgressIfRunningOnPlaneTask($timeEntry);
    }

    /**
     * A task was added to (or changed on) a running timer: move the new task's work item to "In Progress".
     * Note: wasRecentlyCreated stays true for the lifetime of the model instance, so creation is handled in created().
     */
    public function updated(TimeEntry $timeEntry): void
    {
        if (! $timeEntry->wasChanged('task_id')) {
            return;
        }
        $this->moveWorkItemToInProgressIfRunningOnPlaneTask($timeEntry);
    }

    private function moveWorkItemToInProgressIfRunningOnPlaneTask(TimeEntry $timeEntry): void
    {
        if ($timeEntry->end !== null || $timeEntry->task_id === null) {
            return;
        }
        if (! $this->planeClient->isConfigured()) {
            return;
        }

        /** @var Task|null $task */
        $task = Task::query()->find($timeEntry->task_id);
        if ($task === null) {
            return;
        }

        $identifier = PlaneStatusService::workItemIdentifierFromTaskName($task->name);
        if ($identifier === null) {
            return;
        }

        MovePlaneWorkItemToInProgress::dispatchAfterResponse($identifier);
    }
}
