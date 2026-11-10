import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc';
import RunningTimersList from './RunningTimersList.vue';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import type { TimeEntry } from '@/packages/api/src';

dayjs.extend(utc);

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
vi.mock('@/utils/useProjectsQuery', async () => {
    const { computed } = await import('vue');
    return {
        useProjectsQuery: () => ({
            projects: computed(() => [{ id: 'p1', name: 'Website', color: '#f00' }]),
        }),
    };
});
vi.mock('@/utils/useTasksQuery', async () => {
    const { computed } = await import('vue');
    return {
        useTasksQuery: () => ({
            tasks: computed(() => [{ id: 't1', name: '[KARA-98] Multi timer' }]),
        }),
    };
});

const now = dayjs.utc('2026-11-10T10:00:00Z');

const r1 = {
    id: 'r1',
    description: '',
    user_id: 'user-1',
    start: now.subtract(1, 'hour').subtract(2, 'minute').subtract(3, 'second').format(),
    end: null,
    duration: null,
    task_id: 't1',
    project_id: 'p1',
    tags: [],
    billable: false,
    organization_id: 'org-1',
} as unknown as TimeEntry;

function setup(background: TimeEntry[]) {
    const store = useCurrentTimeEntryStore();
    store.backgroundTimeEntries = background;
    store.now = now;
    const wrapper = mount(RunningTimersList);
    return { store, wrapper };
}

function button(wrapper: ReturnType<typeof mount>, label: string) {
    return wrapper.findAll('button').find((candidate) => candidate.text().trim() === label);
}

describe('RunningTimersList', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('renders nothing without background timers', () => {
        const { wrapper } = setup([]);

        expect(wrapper.text()).not.toContain('Running timers');
    });

    it('lists background timers with project, task and live duration', () => {
        const { wrapper } = setup([r1]);

        const text = wrapper.text();
        for (const expected of [
            'Running timers',
            'Website',
            '[KARA-98] Multi timer',
            'No description',
            '01:02:03',
        ]) {
            expect(text).toContain(expected);
        }
    });

    it('Stop calls stopBackgroundTimer with the Plane review offer', async () => {
        const { store, wrapper } = setup([r1]);
        const stop = vi.spyOn(store, 'stopBackgroundTimer').mockResolvedValue(true);

        await button(wrapper, 'Stop')!.trigger('click');

        expect(stop).toHaveBeenCalledWith('r1', { offerPlaneReview: true });
    });

    it('Switch to focuses the timer', async () => {
        const { store, wrapper } = setup([r1]);
        const focus = vi.spyOn(store, 'focusTimer').mockImplementation(() => undefined);

        await button(wrapper, 'Switch to')!.trigger('click');

        expect(focus).toHaveBeenCalledWith('r1');
    });
});
