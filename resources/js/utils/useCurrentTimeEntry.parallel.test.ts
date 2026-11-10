import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useCurrentTimeEntryStore } from './useCurrentTimeEntry';
import type { TimeEntry } from '@/packages/api/src';

const { api, addNotification } = vi.hoisted(() => ({
    api: {
        getMyRunningTimeEntries: vi.fn(),
        updateTimeEntry: vi.fn(),
        createTimeEntry: vi.fn(),
    },
    addNotification: vi.fn(),
}));

vi.mock('@/packages/api/src', () => ({ api }));
vi.mock('@/utils/useUser', () => ({
    getCurrentOrganizationId: () => 'org-1',
    getCurrentMembershipId: () => 'member-1',
    getCurrentUserId: () => 'user-1',
}));
vi.mock('@/utils/notification', () => ({
    useNotificationsStore: () => ({
        handleApiRequestNotifications: async (request: () => Promise<unknown>) => {
            try {
                return await request();
            } catch {
                return undefined;
            }
        },
        addNotification,
    }),
}));
vi.mock('@tanstack/vue-query', () => ({
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}));
vi.mock('@/utils/plane', () => ({
    isPlaneIntegrationEnabled: () => true,
}));

function running(id: string, hoursAgo: number, taskId: string | null = null): TimeEntry {
    return {
        id,
        description: `Work ${id}`,
        user_id: 'user-1',
        start: new Date(Date.now() - hoursAgo * 3600_000).toISOString(),
        end: null,
        duration: null,
        task_id: taskId,
        project_id: 'p1',
        tags: [],
        billable: false,
        organization_id: 'org-1',
    } as unknown as TimeEntry;
}

const r1 = running('r1', 2, 't1');
const r2 = running('r2', 1);

function runningOnServer(...entries: TimeEntry[]) {
    api.getMyRunningTimeEntries.mockResolvedValue({ data: entries.map((entry) => ({ ...entry })) });
}

function ids(entries: TimeEntry[]): string[] {
    return entries.map((entry) => entry.id);
}

describe('useCurrentTimeEntryStore with parallel timers', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('fetch makes the newest running timer the main one and the others background', async () => {
        runningOnServer(r2, r1);
        const store = useCurrentTimeEntryStore();

        await store.fetchCurrentTimeEntry();

        expect(store.currentTimeEntry.id).toBe('r2');
        expect(ids(store.backgroundTimeEntries)).toEqual(['r1']);
        expect(store.hasRunningTimers).toBe(true);
    });

    it('fetch keeps the focused main timer', async () => {
        runningOnServer(r2, r1);
        const store = useCurrentTimeEntryStore();
        await store.fetchCurrentTimeEntry();
        store.focusTimer('r1');

        await store.fetchCurrentTimeEntry();

        expect(store.currentTimeEntry.id).toBe('r1');
        expect(ids(store.backgroundTimeEntries)).toEqual(['r2']);
    });

    it('keeps the draft while composing a new timer', async () => {
        runningOnServer(r2, r1);
        const store = useCurrentTimeEntryStore();
        await store.fetchCurrentTimeEntry();
        store.startAnotherTimer();
        store.currentTimeEntry.description = 'draft';

        await store.fetchCurrentTimeEntry();

        expect(store.currentTimeEntry.id).toBe('');
        expect(store.currentTimeEntry.description).toBe('draft');
        expect(ids(store.backgroundTimeEntries)).toEqual(['r2', 'r1']);
        expect(store.composingNewTimer).toBe(true);
    });

    it('startAnotherTimer moves the main timer to the background', async () => {
        runningOnServer(r1);
        const store = useCurrentTimeEntryStore();
        await store.fetchCurrentTimeEntry();

        store.startAnotherTimer();

        expect(store.currentTimeEntry.id).toBe('');
        expect(store.isActive).toBe(false);
        expect(ids(store.backgroundTimeEntries)).toEqual(['r1']);
        expect(store.hasRunningTimers).toBe(true);
    });

    it('starting the composed timer keeps the others running', async () => {
        runningOnServer(r1);
        const store = useCurrentTimeEntryStore();
        await store.fetchCurrentTimeEntry();
        store.startAnotherTimer();
        const r3 = running('r3', 0);
        api.createTimeEntry.mockResolvedValue({ data: { ...r3 } });

        await store.setActiveState(true);

        expect(store.currentTimeEntry.id).toBe('r3');
        expect(ids(store.backgroundTimeEntries)).toEqual(['r1']);
        expect(store.composingNewTimer).toBe(false);
    });

    it('stopping the main timer promotes the newest background timer', async () => {
        runningOnServer(r2, r1);
        const store = useCurrentTimeEntryStore();
        await store.fetchCurrentTimeEntry();
        api.updateTimeEntry.mockResolvedValue({ data: { ...r2, end: 'x' } });

        await store.setActiveState(false);

        expect(api.updateTimeEntry.mock.calls[0]![1]).toMatchObject({
            params: { timeEntry: 'r2' },
        });
        expect(store.currentTimeEntry.id).toBe('r1');
        expect(store.backgroundTimeEntries).toEqual([]);
        expect(store.isActive).toBe(true);
    });

    it('replacing the main timer leaves background timers alone', async () => {
        runningOnServer(r2, r1);
        const store = useCurrentTimeEntryStore();
        await store.fetchCurrentTimeEntry();
        api.updateTimeEntry.mockResolvedValue({ data: { ...r2, end: 'x' } });

        await store.setActiveState(false, { promoteNextTimer: false });

        expect(store.currentTimeEntry.id).toBe('');
        expect(ids(store.backgroundTimeEntries)).toEqual(['r1']);
        expect(api.updateTimeEntry).toHaveBeenCalledTimes(1);
        expect(api.updateTimeEntry.mock.calls[0]![1]).toMatchObject({
            params: { timeEntry: 'r2' },
        });
    });

    it('stops a background timer that is not the newest', async () => {
        runningOnServer(r2, r1);
        const store = useCurrentTimeEntryStore();
        await store.fetchCurrentTimeEntry();
        api.updateTimeEntry.mockResolvedValue({ data: { ...r1, end: 'x' } });

        const stopped = await store.stopBackgroundTimer('r1');

        expect(stopped).toBe(true);
        expect(api.updateTimeEntry.mock.calls[0]![1]).toEqual({
            params: { organization: 'org-1', timeEntry: 'r1' },
        });
        expect(api.updateTimeEntry.mock.calls[0]![0]).toMatchObject({ start: r1.start });
        expect(api.updateTimeEntry.mock.calls[0]![0].end).toEqual(expect.any(String));
        expect(store.backgroundTimeEntries).toEqual([]);
        expect(store.currentTimeEntry.id).toBe('r2');
    });

    it('drops background timers that stopped on the server', async () => {
        runningOnServer(r2, r1);
        const store = useCurrentTimeEntryStore();
        await store.fetchCurrentTimeEntry();
        runningOnServer(r2);

        const stopped = await store.stopBackgroundTimer('r1');

        expect(stopped).toBe(false);
        expect(api.updateTimeEntry).not.toHaveBeenCalled();
        expect(store.backgroundTimeEntries).toEqual([]);
        expect(addNotification).toHaveBeenCalledWith(
            'error',
            'Your timer was already stopped',
            expect.any(String)
        );
    });

    it('stopping a background task timer offers the Plane review', async () => {
        runningOnServer(r2, r1);
        const store = useCurrentTimeEntryStore();
        await store.fetchCurrentTimeEntry();
        api.updateTimeEntry.mockResolvedValue({ data: { ...r1, end: 'x' } });

        await store.stopBackgroundTimer('r1', { offerPlaneReview: true });

        expect(store.planeReviewCandidate).toEqual({ timeEntryId: 'r1', taskId: 't1' });
    });

    it('focusTimer swaps main and background', async () => {
        runningOnServer(r2, r1);
        const store = useCurrentTimeEntryStore();
        await store.fetchCurrentTimeEntry();

        store.focusTimer('r1');

        expect(store.currentTimeEntry.id).toBe('r1');
        expect(ids(store.backgroundTimeEntries)).toEqual(['r2']);
    });
});
