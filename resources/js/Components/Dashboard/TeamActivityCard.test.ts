import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import TeamActivityCard from './TeamActivityCard.vue';

const { sixWorkingEntries } = vi.hoisted(() => ({
    sixWorkingEntries: Array.from({ length: 6 }, (_, index) => ({
        member_id: `m${index + 1}`,
        name: `Member ${index + 1}`,
        description: `Task ${index + 1}`,
        time_entry_id: `te${index + 1}`,
        task_id: null,
        status: true,
    })),
}));

vi.mock('@tanstack/vue-query', async () => {
    const { ref } = await import('vue');
    return {
        useQuery: () => ({ data: ref(sixWorkingEntries), isLoading: ref(false) }),
    };
});
vi.mock('@/utils/useUser', () => ({ getCurrentOrganizationId: () => 'org-1' }));
vi.mock('@/packages/api/src', () => ({ api: { latestTeamActivity: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({ router: { visit: vi.fn() } }));

describe('TeamActivityCard', () => {
    it('renders every returned member, not just the first four', () => {
        const wrapper = mount(TeamActivityCard);

        for (let i = 1; i <= 6; i++) {
            expect(wrapper.text()).toContain(`Member ${i}`);
        }
        expect(wrapper.text().match(/working/g)).toHaveLength(6);
    });

    it('makes the activity list scrollable so long lists do not stretch the card', () => {
        const wrapper = mount(TeamActivityCard);

        const list = wrapper.get('[data-testid="team_activity_list"]');
        expect(list.classes()).toEqual(expect.arrayContaining(['max-h-64', 'overflow-y-auto']));
    });
});
