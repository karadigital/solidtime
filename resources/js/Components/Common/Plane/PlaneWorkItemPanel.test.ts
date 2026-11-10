import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query';
import PlaneWorkItemPanel from './PlaneWorkItemPanel.vue';
import type { Task } from '@/packages/api/src';

const { api } = vi.hoisted(() => ({
    api: { getTaskPlaneWorkItem: vi.fn() },
}));

vi.mock('@/packages/api/src', () => ({ api }));
vi.mock('@/utils/useUser', () => ({
    getCurrentOrganizationId: () => 'org-1',
}));

function task(id: string, name: string): Task {
    return {
        id,
        name,
        is_done: false,
        project_id: 'p1',
        estimated_time: null,
        spent_time: 0,
        created_at: '2026-11-10T00:00:00Z',
        updated_at: '2026-11-10T00:00:00Z',
    } as Task;
}

function detail(overrides: Record<string, unknown> = {}) {
    return {
        data: {
            identifier: 'KARA-48',
            name: 'View Detail Ticket',
            url: 'https://plane.test/kara/browse/KARA-48/',
            state: { name: 'In Progress', color: '#f59e0b', group: 'started' },
            priority: 'high',
            assignees: [{ display_name: 'akmal' }],
            labels: [{ name: 'frontend', color: '#00f' }],
            start_date: null,
            target_date: '2026-11-20',
            description_html: '<p>Hello <strong>world</strong></p>',
            ...overrides,
        },
    };
}

function mountPanel(selected: Task | null) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return mount(PlaneWorkItemPanel, {
        props: { task: selected },
        global: { plugins: [[VueQueryPlugin, { queryClient }]] },
    });
}

describe('PlaneWorkItemPanel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows a hint when no task is selected', async () => {
        const wrapper = mountPanel(null);
        await flushPromises();

        expect(wrapper.text()).toContain('Select a task to see its Plane details.');
        expect(api.getTaskPlaneWorkItem).not.toHaveBeenCalled();
    });

    it('shows that a task without identifier is not linked', async () => {
        const wrapper = mountPanel(task('t1', 'Finish migration'));
        await flushPromises();

        expect(wrapper.text()).toContain('This task is not linked to a Plane work item.');
        expect(api.getTaskPlaneWorkItem).not.toHaveBeenCalled();
    });

    it('renders the work item', async () => {
        api.getTaskPlaneWorkItem.mockResolvedValue(detail());

        const wrapper = mountPanel(task('t1', '[KARA-48] View Detail Ticket'));
        await flushPromises();

        const text = wrapper.text();
        for (const expected of [
            'KARA-48',
            'View Detail Ticket',
            'In Progress',
            'high',
            'akmal',
            'frontend',
        ]) {
            expect(text).toContain(expected);
        }
        expect(wrapper.find('strong').text()).toBe('world');
        const link = wrapper.findAll('a').find((anchor) => anchor.text().includes('Open in Plane'));
        expect(link?.attributes('href')).toBe('https://plane.test/kara/browse/KARA-48/');
        expect(link?.attributes('target')).toBe('_blank');
        expect(api.getTaskPlaneWorkItem).toHaveBeenCalledWith({
            params: { organization: 'org-1', task: 't1' },
        });
    });

    it('shows No description for an empty description', async () => {
        api.getTaskPlaneWorkItem.mockResolvedValue(detail({ description_html: '' }));

        const wrapper = mountPanel(task('t1', '[KARA-48] View Detail Ticket'));
        await flushPromises();

        expect(wrapper.text()).toContain('No description.');
    });

    it('shows the not found message on 404', async () => {
        api.getTaskPlaneWorkItem.mockRejectedValue({ response: { status: 404 } });

        const wrapper = mountPanel(task('t1', '[KARA-48] View Detail Ticket'));
        await flushPromises();

        expect(wrapper.text()).toContain('KARA-48 was not found in Plane');
        expect(wrapper.text()).toContain(
            'The work item does not exist, or the Plane bot is not a member of its project.'
        );
    });

    it('shows the unreachable message with retry on other errors', async () => {
        api.getTaskPlaneWorkItem.mockRejectedValue({ response: { status: 502 } });

        const wrapper = mountPanel(task('t1', '[KARA-48] View Detail Ticket'));
        await flushPromises();

        expect(wrapper.text()).toContain('Plane could not be reached. Please try again later.');
        const retry = wrapper.findAll('button').find((button) => button.text() === 'Retry');
        expect(retry).toBeDefined();

        await retry!.trigger('click');
        await flushPromises();

        expect(api.getTaskPlaneWorkItem).toHaveBeenCalledTimes(2);
    });

    it('shows the detail of the newly selected task', async () => {
        api.getTaskPlaneWorkItem.mockImplementation(({ params }: { params: { task: string } }) =>
            Promise.resolve(
                params.task === 't1'
                    ? detail()
                    : detail({ identifier: 'SUBE-12', name: 'Other work item' })
            )
        );

        const wrapper = mountPanel(task('t1', '[KARA-48] View Detail Ticket'));
        await flushPromises();
        await wrapper.setProps({ task: task('t2', '[SUBE-12] Other') });
        await flushPromises();

        expect(api.getTaskPlaneWorkItem).toHaveBeenLastCalledWith({
            params: { organization: 'org-1', task: 't2' },
        });
        expect(wrapper.text()).toContain('SUBE-12');
        expect(wrapper.text()).toContain('Other work item');
        expect(wrapper.text()).not.toContain('KARA-48');
    });
});
