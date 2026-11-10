import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import PlaneReviewPrompt from './PlaneReviewPrompt.vue';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';

const { api, addNotification, tasks } = vi.hoisted(() => ({
    api: {
        moveTimeEntryTaskToPlaneReview: vi.fn(),
        getMyActiveTimeEntry: vi.fn(),
        updateTimeEntry: vi.fn(),
        createTimeEntry: vi.fn(),
    },
    addNotification: vi.fn(),
    tasks: { value: [] as { id: string; name: string }[] },
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
vi.mock('@/utils/useTasksQuery', async () => {
    const { computed, ref } = await import('vue');
    return {
        useTasksQuery: () => ({
            tasks: computed(() => tasks.value),
            isSuccess: ref(true),
        }),
    };
});
vi.mock('@/utils/plane', async () => {
    const actual = await vi.importActual<typeof import('@/utils/plane')>('@/utils/plane');
    return { ...actual, isPlaneIntegrationEnabled: () => true };
});

function findButton(label: string): HTMLButtonElement | undefined {
    return Array.from(document.body.querySelectorAll('button')).find(
        (button) => button.textContent?.trim() === label
    );
}

describe('PlaneReviewPrompt', () => {
    let wrapper: VueWrapper | undefined;

    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        tasks.value = [{ id: 't1', name: '[KARA-47] Auto change' }];
    });

    afterEach(() => {
        wrapper?.unmount();
        document.body.innerHTML = '';
    });

    async function mountWithCandidate(taskId = 't1') {
        const store = useCurrentTimeEntryStore();
        wrapper = mount(PlaneReviewPrompt, { attachTo: document.body });
        store.planeReviewCandidate = { timeEntryId: 'te-1', taskId };
        await flushPromises();
        return store;
    }

    it('asks to move the work item to review and moves it on confirm', async () => {
        api.moveTimeEntryTaskToPlaneReview.mockResolvedValue({
            data: { identifier: 'KARA-47', outcome: 'moved', state: 'In Review' },
        });
        const store = await mountWithCandidate();

        expect(document.body.textContent).toContain('Move KARA-47 to review?');
        findButton('Move to review')!.click();
        await flushPromises();

        expect(api.moveTimeEntryTaskToPlaneReview).toHaveBeenCalledWith(undefined, {
            params: { organization: 'org-1', timeEntry: 'te-1' },
        });
        expect(addNotification).toHaveBeenCalledWith('success', 'Moved KARA-47 to In Review');
        expect(store.planeReviewCandidate).toBeNull();
    });

    it('keeps the status when the user is not done yet', async () => {
        const store = await mountWithCandidate();

        findButton('Not yet')!.click();
        await flushPromises();

        expect(api.moveTimeEntryTaskToPlaneReview).not.toHaveBeenCalled();
        expect(store.planeReviewCandidate).toBeNull();
    });

    it('shows an error when the work item does not exist in Plane', async () => {
        api.moveTimeEntryTaskToPlaneReview.mockResolvedValue({
            data: { identifier: 'KARA-47', outcome: 'work_item_not_found', state: null },
        });
        await mountWithCandidate();

        findButton('Move to review')!.click();
        await flushPromises();

        expect(addNotification).toHaveBeenCalledWith('error', 'KARA-47 was not found in Plane');
    });

    it('does nothing for tasks that are not Plane work items', async () => {
        tasks.value = [{ id: 't2', name: 'Finish migration' }];
        const store = await mountWithCandidate('t2');

        expect(document.body.textContent).not.toContain('to review?');
        expect(store.planeReviewCandidate).toBeNull();
    });
});
