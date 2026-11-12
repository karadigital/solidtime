import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import TimeTracker from './TimeTracker.vue';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import type { TimeEntry } from '@/packages/api/src';

const { organization } = vi.hoisted(() => ({
    organization: { value: { prevent_overlapping_time_entries: false } as Record<string, unknown> },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { current_team_id: null } } } }),
}));
vi.mock('@/packages/api/src', () => ({ api: {} }));
vi.mock('@/utils/useUser', () => ({
    getCurrentOrganizationId: () => 'org-1',
    getCurrentMembershipId: () => 'member-1',
    getCurrentUserId: () => 'user-1',
}));
vi.mock('@/utils/notification', () => ({
    useNotificationsStore: () => ({
        handleApiRequestNotifications: (request: () => Promise<unknown>) => request(),
        addNotification: vi.fn(),
    }),
}));
vi.mock('@tanstack/vue-query', () => ({
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}));
vi.mock('@/utils/plane', () => ({ isPlaneIntegrationEnabled: () => false }));
vi.mock('@/utils/useOrganizationQuery', async () => {
    const { computed } = await import('vue');
    return { useOrganizationQuery: () => ({ organization: computed(() => organization.value) }) };
});
vi.mock('@/utils/useProjectsQuery', async () => {
    const { computed } = await import('vue');
    return { useProjectsQuery: () => ({ projects: computed(() => []) }) };
});
vi.mock('@/utils/useTasksQuery', async () => {
    const { computed } = await import('vue');
    return { useTasksQuery: () => ({ tasks: computed(() => []) }) };
});
vi.mock('@/utils/useTagsQuery', async () => {
    const { computed } = await import('vue');
    return { useTagsQuery: () => ({ tags: computed(() => []) }) };
});
vi.mock('@/utils/useClientsQuery', async () => {
    const { computed } = await import('vue');
    return { useClientsQuery: () => ({ clients: computed(() => []) }) };
});
vi.mock('@/utils/useTags', () => ({ useTagsStore: () => ({}) }));
vi.mock('@/utils/useProjects', () => ({ useProjectsStore: () => ({}) }));
vi.mock('@/utils/useClients', () => ({ useClientsStore: () => ({}) }));
vi.mock('@/utils/useTimeEntriesMutations', () => ({
    useTimeEntriesMutations: () => ({ createTimeEntry: vi.fn(), deleteTimeEntry: vi.fn() }),
}));
vi.mock('@/utils/useTimeEntriesInfiniteQuery', async () => {
    const { ref } = await import('vue');
    return { useTimeEntriesInfiniteQuery: () => ({ data: ref(undefined) }) };
});
vi.mock('@/utils/money', () => ({ getOrganizationCurrencyString: () => 'EUR' }));
vi.mock('@/utils/billing', () => ({ isAllowedToPerformPremiumAction: () => true }));
vi.mock('@/utils/permissions', () => ({ canCreateProjects: () => false }));

const runningEntry = {
    id: 'r1',
    description: 'Work',
    user_id: 'user-1',
    start: '2026-11-10T09:00:00Z',
    end: null,
    duration: null,
    task_id: null,
    project_id: null,
    tags: [],
    billable: false,
    organization_id: 'org-1',
} as unknown as TimeEntry;

function mountTracker() {
    return mount(TimeTracker, {
        global: {
            stubs: {
                TimeTrackerControls: true,
                TimeTrackerMoreOptionsDropdown: true,
                TimeEntryCreateModal: true,
                RunningTimersList: true,
                TimeTrackerRunningInDifferentOrganizationOverlay: true,
            },
        },
    });
}

function newTimerButton(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('button').find((button) => button.text().trim() === 'New timer');
}

describe('TimeTracker with parallel timers', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        organization.value = { prevent_overlapping_time_entries: false };
    });

    it('shows New timer only while a timer runs', async () => {
        const store = useCurrentTimeEntryStore();
        const wrapper = mountTracker();
        expect(newTimerButton(wrapper)).toBeUndefined();

        store.currentTimeEntry = { ...runningEntry };
        await wrapper.vm.$nextTick();
        const button = newTimerButton(wrapper);
        expect(button).toBeDefined();

        const startAnother = vi.spyOn(store, 'startAnotherTimer');
        await button!.trigger('click');
        expect(startAnother).toHaveBeenCalledTimes(1);
    });

    it('hides New timer when the organization prevents overlapping', async () => {
        organization.value = { prevent_overlapping_time_entries: true };
        const store = useCurrentTimeEntryStore();
        store.currentTimeEntry = { ...runningEntry };

        const wrapper = mountTracker();
        await wrapper.vm.$nextTick();

        expect(newTimerButton(wrapper)).toBeUndefined();
    });
});
