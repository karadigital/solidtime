import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api } from '@/packages/api/src';
import type { TimeEntry } from '@/packages/api/src';
import dayjs, { Dayjs } from 'dayjs';
import utc from 'dayjs/plugin/utc';
import {
    getCurrentMembershipId,
    getCurrentOrganizationId,
    getCurrentUserId,
} from '@/utils/useUser';
import { useLocalStorage } from '@vueuse/core';
import { useNotificationsStore } from '@/utils/notification';
import { useQueryClient } from '@tanstack/vue-query';
import { isPlaneIntegrationEnabled } from '@/utils/plane';

dayjs.extend(utc);

const emptyTimeEntry = {
    id: '',
    description: '',
    user_id: '',
    start: '',
    end: null,
    duration: null,
    task_id: null,
    project_id: null,
    tags: [],
    billable: false,
    organization_id: '',
} as TimeEntry;

export const useCurrentTimeEntryStore = defineStore('currentTimeEntry', () => {
    const currentTimeEntry = ref<TimeEntry>({ ...emptyTimeEntry });
    const { handleApiRequestNotifications, addNotification } = useNotificationsStore();
    const queryClient = useQueryClient();

    useLocalStorage('solidtime/current-time-entry', currentTimeEntry, {
        deep: true,
    });

    /**
     * Set after the user stopped a timer on a task, so the user can be asked whether to move
     * the task's Plane work item to review (see PlaneReviewPrompt).
     */
    const planeReviewCandidate = ref<{ timeEntryId: string; taskId: string } | null>(null);

    function $reset() {
        currentTimeEntry.value = { ...emptyTimeEntry };
    }

    const now = ref<null | Dayjs>(null);
    const interval = ref<ReturnType<typeof setInterval> | null>(null);

    function startLiveTimer() {
        stopLiveTimer();
        now.value = dayjs().utc();
        interval.value = setInterval(() => {
            now.value = dayjs().utc();
        }, 1000);
    }

    function stopLiveTimer() {
        if (interval.value !== null) {
            clearInterval(interval.value);
        }
    }

    async function fetchCurrentTimeEntry() {
        const organizationId = getCurrentOrganizationId();
        if (organizationId) {
            try {
                const timeEntriesResponse = await api.getMyActiveTimeEntry({});
                if (timeEntriesResponse?.data) {
                    if (timeEntriesResponse.data) {
                        currentTimeEntry.value = timeEntriesResponse.data;
                        if (
                            currentTimeEntry.value.start !== '' &&
                            currentTimeEntry.value.end === null
                        ) {
                            startLiveTimer();
                        }
                    } else {
                        // No active time entry on server
                        // Only reset if we had a previously started timer (has an ID)
                        // Don't reset if user is preparing a new time entry (no ID yet)
                        if (currentTimeEntry.value.id !== '') {
                            currentTimeEntry.value = { ...emptyTimeEntry };
                            stopLiveTimer();
                        }
                    }
                }
            } catch {
                // API error (e.g., 404 when no active time entry)
                // Only reset if we had a previously started timer (has an ID)
                // Don't reset if user is preparing a new time entry (no ID yet)
                if (currentTimeEntry.value.id !== '') {
                    currentTimeEntry.value = { ...emptyTimeEntry };
                    stopLiveTimer();
                }
            }
        } else {
            throw new Error(
                'Failed to fetch current time entry because organization ID is missing.'
            );
        }
    }

    async function startTimer() {
        const organization = getCurrentOrganizationId();
        const membership = getCurrentMembershipId();
        if (organization && membership) {
            const startTime =
                currentTimeEntry.value.start !== ''
                    ? currentTimeEntry.value.start
                    : dayjs().utc().format();
            const response = await handleApiRequestNotifications(
                () =>
                    api.createTimeEntry(
                        {
                            member_id: membership,
                            start: startTime,
                            description: currentTimeEntry.value?.description,
                            project_id: currentTimeEntry.value?.project_id,
                            task_id: currentTimeEntry.value?.task_id,
                            billable: currentTimeEntry.value.billable,
                            tags: currentTimeEntry.value?.tags,
                        },
                        { params: { organization: organization } }
                    ),
                'Timer started!'
            );
            if (response?.data) {
                currentTimeEntry.value = response.data;
            }
        } else {
            throw new Error(
                'Failed to fetch current time entry because organization ID is missing.'
            );
        }
    }

    /**
     * The timer may have been stopped on the server in the meantime (e.g. by the auto stop at the end of the
     * workday, or on another device). Writing to it then would overwrite its end, so check first.
     * Returns false (and resets the local timer) if the current time entry is no longer running on the server.
     */
    async function ensureCurrentTimeEntryIsStillRunning(): Promise<boolean> {
        const timeEntryId = currentTimeEntry.value.id;
        let stillRunning: boolean;
        try {
            const response = await api.getMyActiveTimeEntry({});
            stillRunning = response?.data?.id === timeEntryId;
        } catch (error) {
            const status = (error as { response?: { status?: number } })?.response?.status;
            // Only a 404 means "no running time entry"; for other errors keep the previous behaviour
            stillRunning = status !== 404;
        }
        if (!stillRunning) {
            $reset();
            stopLiveTimer();
            addNotification(
                'error',
                'Your timer was already stopped',
                'It was stopped automatically or on another device. Your change was not saved.'
            );
            queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
        }
        return stillRunning;
    }

    /**
     * @returns true if the timer was stopped on the server
     */
    async function stopTimer(): Promise<boolean> {
        const user = getCurrentUserId();
        const organization = getCurrentOrganizationId();
        if (organization) {
            if (!(await ensureCurrentTimeEntryIsStillRunning())) {
                return false;
            }
            const currentDateTime = dayjs().utc().format();
            const response = await handleApiRequestNotifications(
                () =>
                    api.updateTimeEntry(
                        {
                            user_id: user,
                            start: currentTimeEntry.value.start,
                            end: currentDateTime,
                        },
                        {
                            params: {
                                organization: organization,
                                timeEntry: currentTimeEntry.value.id,
                            },
                        }
                    ),
                'Timer stopped!'
            );
            $reset();
            return response?.data !== undefined;
        } else {
            throw new Error('Failed to stop current timer because organization ID is missing.');
        }
    }

    async function updateTimer() {
        const user = getCurrentUserId();
        const organization = getCurrentOrganizationId();
        if (organization) {
            if (
                currentTimeEntry.value.end === null &&
                !(await ensureCurrentTimeEntryIsStillRunning())
            ) {
                return;
            }
            const response = await handleApiRequestNotifications(
                () =>
                    api.updateTimeEntry(
                        {
                            description: currentTimeEntry.value.description,
                            user_id: user,
                            project_id: currentTimeEntry.value.project_id,
                            task_id: currentTimeEntry.value.task_id,
                            start: currentTimeEntry.value.start,
                            billable: currentTimeEntry.value.billable,
                            end: currentTimeEntry.value.end,
                            tags: currentTimeEntry.value.tags,
                        },
                        {
                            params: {
                                organization: organization,
                                timeEntry: currentTimeEntry.value.id,
                            },
                        }
                    ),
                'Time entry updated!'
            );
            if (response?.data) {
                if (response.data.end === null) {
                    currentTimeEntry.value = response.data;
                } else {
                    $reset();
                    stopLiveTimer();
                }
            }
        } else {
            throw new Error(
                'Failed to fetch current time entry because organization ID is missing.'
            );
        }
    }

    const isActive = computed(() => {
        if (currentTimeEntry.value) {
            return (
                currentTimeEntry.value.start !== '' &&
                currentTimeEntry.value.start !== null &&
                currentTimeEntry.value.end === null
            );
        }
        return false;
    });

    /**
     * @param options.offerPlaneReview Only for the user's explicit Stop action: offers to move the task's Plane work
     *                                 item to review. Stops that only precede starting another timer don't offer it.
     */
    async function setActiveState(newState: boolean, options: { offerPlaneReview?: boolean } = {}) {
        if (newState) {
            startLiveTimer();
            await startTimer();
        } else {
            stopLiveTimer();
            const stoppedTimeEntryId = currentTimeEntry.value.id;
            const stoppedTaskId = currentTimeEntry.value.task_id;
            const stopped = await stopTimer();
            if (
                options.offerPlaneReview === true &&
                stopped &&
                stoppedTaskId !== null &&
                isPlaneIntegrationEnabled()
            ) {
                planeReviewCandidate.value = {
                    timeEntryId: stoppedTimeEntryId,
                    taskId: stoppedTaskId,
                };
            }
        }
        queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
    }

    return {
        currentTimeEntry,
        fetchCurrentTimeEntry,
        updateTimer,
        isActive,
        startLiveTimer,
        stopLiveTimer,
        now,
        setActiveState,
        planeReviewCandidate,
        $reset,
    };
});
