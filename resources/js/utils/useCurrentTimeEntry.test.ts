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
        handleApiRequestNotifications: (request: () => Promise<unknown>) => request(),
        addNotification,
    }),
}));
vi.mock('@tanstack/vue-query', () => ({
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}));

const runningEntry = {
    id: 'te-1',
    description: 'Work',
    user_id: 'user-1',
    start: '2026-11-13T02:00:00Z',
    end: null,
    duration: null,
    task_id: null,
    project_id: null,
    tags: [],
    billable: false,
    organization_id: 'org-1',
} as unknown as TimeEntry;

describe('useCurrentTimeEntryStore with a timer stopped on the server', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('does not overwrite the end of a timer that was already stopped on the server', async () => {
        api.getMyRunningTimeEntries.mockResolvedValue({ data: [] });
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry = { ...runningEntry };

        await store.setActiveState(false);

        expect(api.updateTimeEntry).not.toHaveBeenCalled();
        expect(store.currentTimeEntry.id).toBe('');
        expect(addNotification).toHaveBeenCalledWith(
            'error',
            'Your timer was already stopped',
            expect.any(String)
        );
    });

    it('stops the timer when it is still running on the server', async () => {
        api.getMyRunningTimeEntries.mockResolvedValue({ data: [{ ...runningEntry }] });
        api.updateTimeEntry.mockResolvedValue({ data: { ...runningEntry, end: 'x' } });
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry = { ...runningEntry };

        await store.setActiveState(false);

        expect(api.updateTimeEntry).toHaveBeenCalledTimes(1);
        expect(api.updateTimeEntry.mock.calls[0]![0]).toMatchObject({ start: runningEntry.start });
        expect(api.updateTimeEntry.mock.calls[0]![0].end).toEqual(expect.any(String));
    });

    it('does not update a timer that was already stopped on the server', async () => {
        api.getMyRunningTimeEntries.mockResolvedValue({ data: [] });
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry = { ...runningEntry, description: 'Changed' };

        await store.updateTimer();

        expect(api.updateTimeEntry).not.toHaveBeenCalled();
        expect(store.currentTimeEntry.id).toBe('');
    });
});
