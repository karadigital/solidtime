<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\TimeEntry\TimeEntryResource;
use App\Models\Organization;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class UserTimeEntryController extends Controller
{
    /**
     * Get the most recently started running time entry of the current user
     *
     * A user can have several running time entries, see getMyRunningTimeEntries for all of them.
     * This endpoint is independent of the organization.
     *
     * @operationId getMyActiveTimeEntry
     */
    public function myActive(): JsonResource
    {
        $user = $this->user();

        $activeTimeEntriesOfUser = TimeEntry::query()
            ->whereBelongsTo($user, 'user')
            ->whereNull('end')
            ->orderBy('start', 'desc')
            ->get();

        $activeTimeEntry = $activeTimeEntriesOfUser->first();

        if ($activeTimeEntry !== null) {
            return new TimeEntryResource($activeTimeEntry);
        } else {
            throw new ModelNotFoundException('No active time entry');
        }
    }

    /**
     * Get all running time entries of the current user
     *
     * Newest first. This endpoint is independent of the organization.
     *
     * @return AnonymousResourceCollection<int, TimeEntryResource>
     *
     * @operationId getMyRunningTimeEntries
     */
    public function myRunning(): AnonymousResourceCollection
    {
        $runningTimeEntries = TimeEntry::query()
            ->whereBelongsTo($this->user(), 'user')
            ->whereNull('end')
            ->orderBy('start', 'desc')
            ->get();

        return TimeEntryResource::collection($runningTimeEntries);
    }
}
