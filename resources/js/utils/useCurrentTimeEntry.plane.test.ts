import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useCurrentTimeEntryStore } from './useCurrentTimeEntry';
import type { TimeEntry } from '@/packages/api/src';

const { api, planeEnabled } = vi.hoisted(() => ({
    api: {
        getMyActiveTimeEntry: vi.fn(),
        updateTimeEntry: vi.fn(),
        createTimeEntry: vi.fn(),
    },
    planeEnabled: { value: true },
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
        addNotification: vi.fn(),
    }),
}));
vi.mock('@tanstack/vue-query', () => ({
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}));
vi.mock('@/utils/plane', () => ({
    isPlaneIntegrationEnabled: () => planeEnabled.value,
}));

const runningEntry = {
    id: 'te-1',
    description: 'Work',
    user_id: 'user-1',
    start: '2026-11-10T02:00:00Z',
    end: null,
    duration: null,
    task_id: 't1',
    project_id: 'p1',
    tags: [],
    billable: false,
    organization_id: 'org-1',
} as unknown as TimeEntry;

describe('useCurrentTimeEntryStore Plane review prompt', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        planeEnabled.value = true;
        api.getMyActiveTimeEntry.mockResolvedValue({ data: { ...runningEntry } });
    });

    it('offers a Plane review after stopping a timer on a task', async () => {
        api.updateTimeEntry.mockResolvedValue({
            data: { ...runningEntry, end: '2026-11-10T03:00:00Z' },
        });
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry = { ...runningEntry };

        await store.setActiveState(false, { offerPlaneReview: true });

        expect(store.planeReviewCandidate).toEqual({ timeEntryId: 'te-1', taskId: 't1' });
    });

    it('does not offer a review when the timer is stopped to start another one', async () => {
        api.updateTimeEntry.mockResolvedValue({
            data: { ...runningEntry, end: '2026-11-10T03:00:00Z' },
        });
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry = { ...runningEntry };

        await store.setActiveState(false);

        expect(store.planeReviewCandidate).toBeNull();
    });

    it('does not offer a review when the stop failed', async () => {
        api.updateTimeEntry.mockRejectedValue(new Error('Server error'));
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry = { ...runningEntry };

        await store.setActiveState(false, { offerPlaneReview: true });

        expect(store.planeReviewCandidate).toBeNull();
    });

    it('does not offer a review when the integration is disabled', async () => {
        planeEnabled.value = false;
        api.updateTimeEntry.mockResolvedValue({
            data: { ...runningEntry, end: '2026-11-10T03:00:00Z' },
        });
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry = { ...runningEntry };

        await store.setActiveState(false, { offerPlaneReview: true });

        expect(store.planeReviewCandidate).toBeNull();
    });

    it('does not offer a review for a timer without task', async () => {
        api.updateTimeEntry.mockResolvedValue({
            data: { ...runningEntry, task_id: null, end: 'x' },
        });
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry = { ...runningEntry, task_id: null };

        await store.setActiveState(false, { offerPlaneReview: true });

        expect(store.planeReviewCandidate).toBeNull();
    });
});
