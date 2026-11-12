<?php

declare(strict_types=1);

namespace App\Service\Plane;

/**
 * Moves Plane work items between workflow states, based on the time tracker.
 * solidtime tasks are named after Plane work items, f.e. "[KARA-47] Auto change status".
 */
class PlaneStatusService
{
    private const string IN_PROGRESS_STATE = 'In Progress';

    /**
     * Review states in order of preference
     */
    private const array REVIEW_STATES = ['In Review', 'Ready for Test'];

    private const array CLOSED_STATE_GROUPS = ['completed', 'cancelled'];

    public function __construct(private readonly PlaneClient $client) {}

    /**
     * Extracts the Plane work item identifier (f.e. "KARA-47") from a task name like "[KARA-47] Title".
     */
    public static function workItemIdentifierFromTaskName(string $taskName): ?string
    {
        if (preg_match('/^\[([A-Z][A-Z0-9]*-[0-9]+)\]/', $taskName, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @throws PlaneApiException
     */
    public function moveToInProgress(string $identifier): PlaneStatusResult
    {
        return $this->moveTo($identifier, [self::IN_PROGRESS_STATE]);
    }

    /**
     * @throws PlaneApiException
     */
    public function moveToReview(string $identifier): PlaneStatusResult
    {
        return $this->moveTo($identifier, self::REVIEW_STATES);
    }

    /**
     * @param  list<string>  $targetStateNames  in order of preference
     *
     * @throws PlaneApiException
     */
    private function moveTo(string $identifier, array $targetStateNames): PlaneStatusResult
    {
        $workItem = $this->client->findWorkItem($identifier);
        if ($workItem === null) {
            return new PlaneStatusResult(PlaneTransitionOutcome::WorkItemNotFound);
        }

        $states = $this->client->listStates($workItem['project']);

        $currentState = null;
        foreach ($states as $state) {
            if ($state['id'] === $workItem['state']) {
                $currentState = $state;
                break;
            }
        }
        if ($currentState !== null && in_array($currentState['group'], self::CLOSED_STATE_GROUPS, true)) {
            return new PlaneStatusResult(PlaneTransitionOutcome::WorkItemClosed, $currentState['name']);
        }

        $targetState = $this->findStateByName($states, $targetStateNames);
        if ($targetState === null) {
            return new PlaneStatusResult(PlaneTransitionOutcome::StateNotFound);
        }

        $targetStateName = trim($targetState['name']);
        if ($targetState['id'] === $workItem['state']) {
            return new PlaneStatusResult(PlaneTransitionOutcome::AlreadyInState, $targetStateName);
        }

        $this->client->updateWorkItemState($workItem['project'], $workItem['id'], $targetState['id']);

        return new PlaneStatusResult(PlaneTransitionOutcome::Moved, $targetStateName);
    }

    /**
     * @param  list<array{id: string, name: string, group: string}>  $states
     * @param  list<string>  $names  in order of preference
     * @return array{id: string, name: string, group: string}|null
     */
    private function findStateByName(array $states, array $names): ?array
    {
        foreach ($names as $name) {
            foreach ($states as $state) {
                if (mb_strtolower(trim($state['name'])) === mb_strtolower($name)) {
                    return $state;
                }
            }
        }

        return null;
    }
}
