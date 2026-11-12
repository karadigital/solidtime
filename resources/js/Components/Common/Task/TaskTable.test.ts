import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import TaskTable from './TaskTable.vue';
import type { Task } from '@/packages/api/src';

vi.mock('@/utils/permissions', () => ({
    canCreateTasks: () => false,
    canDeleteTasks: () => false,
}));
vi.mock('@/utils/billing', () => ({
    isAllowedToPerformPremiumAction: () => true,
}));
vi.mock('@/utils/useTasks', () => ({
    useTasksStore: () => ({ deleteTask: vi.fn(), updateTask: vi.fn() }),
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

const tasks = [task('t1', '[KARA-48] View detail'), task('t2', '[KARA-49] Other')];

function mountTable(selectedTaskId: string | null = null) {
    return mount(TaskTable, {
        props: { projectId: 'p1', tasks, selectable: true, selectedTaskId },
        global: {
            stubs: { TaskCreateModal: true, TaskEditModal: true, TaskMoreOptionsDropdown: true },
        },
    });
}

describe('TaskTable', () => {
    it('emits select when a task name is clicked', async () => {
        const wrapper = mountTable();

        const button = wrapper
            .findAll('button')
            .find((candidate) => candidate.text() === '[KARA-49] Other');
        expect(button).toBeDefined();
        await button!.trigger('click');

        expect(wrapper.emitted('select')).toEqual([[tasks[1]]]);
    });

    it('renders plain task names when not selectable', () => {
        const wrapper = mount(TaskTable, {
            props: { projectId: 'p1', tasks },
            global: {
                stubs: {
                    TaskCreateModal: true,
                    TaskEditModal: true,
                    TaskMoreOptionsDropdown: true,
                },
            },
        });

        expect(
            wrapper.findAll('button').filter((candidate) => candidate.text().startsWith('[KARA-'))
        ).toHaveLength(0);
        expect(wrapper.text()).toContain('[KARA-48] View detail');
    });

    it('marks the selected row', () => {
        const wrapper = mountTable('t1');

        const buttons = wrapper
            .findAll('button')
            .filter((candidate) => candidate.text().startsWith('[KARA-'));
        expect(buttons[0]!.attributes('aria-current')).toBe('true');
        expect(buttons[1]!.attributes('aria-current')).toBeUndefined();
    });
});
