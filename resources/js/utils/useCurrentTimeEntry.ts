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

    /**
     * Running timers other than the main one shown in the time tracker, newest first (KARA-98).
     */
    const backgroundTimeEntries = ref<TimeEntry[]>([]);

    /**
     * True after "New timer" until a timer is started or focused: the tracker holds a draft that a fetch must keep.
     */
    const composingNewTimer = ref(false);

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
        if (!organizationId) {
            throw new Error(
                'Failed to fetch current time entry because organization ID is missing.'
            );
        }
        let running: TimeEntry[];
        try {
            const response = await api.getMyRunningTimeEntries({});
            running = response?.data ?? [];
        } catch {
            return;
        }

        if (composingNewTimer.value) {
            backgroundTimeEntries.value = running;
        } else {
            const main =
                running.find((timeEntry) => timeEntry.id === currentTimeEntry.value.id) ??
                running[0] ??
                null;
            if (main !== null) {
                currentTimeEntry.value = main;
            } else if (currentTimeEntry.value.id !== '') {
                // The timer was stopped elsewhere. A draft without id (not started yet) is kept.
                $reset();
            }
            backgroundTimeEntries.value = running.filter((timeEntry) => timeEntry.id !== main?.id);
        }

        if (running.length > 0) {
            startLiveTimer();
        } else {
            stopLiveTimer();
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
                composingNewTimer.value = false;
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
    async function isStillRunning(timeEntryId: string): Promise<boolean> {
        try {
            const response = await api.getMyRunningTimeEntries({});
            return (response?.data ?? []).some((timeEntry) => timeEntry.id === timeEntryId);
        } catch {
            // Keep the previous behaviour if the check itself fails
            return true;
        }
    }

    function notifyAlreadyStopped() {
        addNotification(
            'error',
            'Your timer was already stopped',
            'It was stopped automatically or on another device. Your change was not saved.'
        );
        queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
    }

    async function ensureCurrentTimeEntryIsStillRunning(): Promise<boolean> {
        const stillRunning = await isStillRunning(currentTimeEntry.value.id);
        if (!stillRunning) {
            $reset();
            if (backgroundTimeEntries.value.length === 0) {
                stopLiveTimer();
            }
            notifyAlreadyStopped();
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
                    if (backgroundTimeEntries.value.length === 0) {
                        stopLiveTimer();
                    }
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

    const hasRunningTimers = computed(
        () => isActive.value || backgroundTimeEntries.value.length > 0
    );

    function offerPlaneReviewFor(timeEntryId: string, taskId: string | null) {
        if (taskId !== null && isPlaneIntegrationEnabled()) {
            planeReviewCandidate.value = { timeEntryId, taskId };
        }
    }

    /**
     * @param options.offerPlaneReview Only for the user's explicit Stop action: offers to move the task's Plane work
     *                                 item to review. Stops that only precede starting another timer don't offer it.
     * @param options.promoteNextTimer After stopping, show the newest background timer in the tracker (default).
     *                                 Quick-start actions that replace the main timer pass false.
     */
    async function setActiveState(
        newState: boolean,
        options: { offerPlaneReview?: boolean; promoteNextTimer?: boolean } = {}
    ) {
        if (newState) {
            startLiveTimer();
            await startTimer();
        } else {
            const stoppedTimeEntryId = currentTimeEntry.value.id;
            const stoppedTaskId = currentTimeEntry.value.task_id;
            const stopped = await stopTimer();
            if (options.offerPlaneReview === true && stopped) {
                offerPlaneReviewFor(stoppedTimeEntryId, stoppedTaskId);
            }
            const next = backgroundTimeEntries.value[0];
            if (options.promoteNextTimer !== false && currentTimeEntry.value.id === '' && next) {
                currentTimeEntry.value = next;
                backgroundTimeEntries.value = backgroundTimeEntries.value.slice(1);
            }
            if (!hasRunningTimers.value) {
                stopLiveTimer();
            }
        }
        queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
    }

    /**
     * Keeps the main timer running in the background and opens an empty tracker for a new timer.
     */
    function startAnotherTimer() {
        if (isActive.value) {
            backgroundTimeEntries.value = [currentTimeEntry.value, ...backgroundTimeEntries.value];
        }
        $reset();
        composingNewTimer.value = true;
    }

    /**
     * Shows a background timer in the tracker. The previous main timer keeps running in the background; an
     * unsaved draft is discarded.
     */
    function focusTimer(timeEntryId: string) {
        const timeEntry = backgroundTimeEntries.value.find((entry) => entry.id === timeEntryId);
        if (!timeEntry) {
            return;
        }
        const others = backgroundTimeEntries.value.filter((entry) => entry.id !== timeEntryId);
        backgroundTimeEntries.value = isActive.value ? [currentTimeEntry.value, ...others] : others;
        currentTimeEntry.value = timeEntry;
        composingNewTimer.value = false;
    }

    /**
     * @returns true if the timer was stopped on the server
     */
    async function stopBackgroundTimer(
        timeEntryId: string,
        options: { offerPlaneReview?: boolean } = {}
    ): Promise<boolean> {
        const timeEntry = backgroundTimeEntries.value.find((entry) => entry.id === timeEntryId);
        if (!timeEntry) {
            return false;
        }
        const removeFromBackground = () => {
            backgroundTimeEntries.value = backgroundTimeEntries.value.filter(
                (entry) => entry.id !== timeEntryId
            );
            if (!hasRunningTimers.value) {
                stopLiveTimer();
            }
        };
        if (!(await isStillRunning(timeEntryId))) {
            removeFromBackground();
            notifyAlreadyStopped();
            return false;
        }
        const response = await handleApiRequestNotifications(
            () =>
                api.updateTimeEntry(
                    {
                        user_id: getCurrentUserId(),
                        start: timeEntry.start,
                        end: dayjs().utc().format(),
                    },
                    {
                        params: {
                            organization: timeEntry.organization_id,
                            timeEntry: timeEntryId,
                        },
                    }
                ),
            'Timer stopped!'
        );
        const stopped = response?.data !== undefined;
        if (stopped) {
            removeFromBackground();
            if (options.offerPlaneReview === true) {
                offerPlaneReviewFor(timeEntryId, timeEntry.task_id);
            }
        }
        queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
        return stopped;
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
        backgroundTimeEntries,
        composingNewTimer,
        hasRunningTimers,
        startAnotherTimer,
        focusTimer,
        stopBackgroundTimer,
        $reset,
    };
});
