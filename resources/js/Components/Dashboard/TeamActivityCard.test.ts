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
        expect(list.classes()).toContain('overflow-y-auto');
    });

    it('caps the list at a height that leaves part of a fifth entry visible, hinting that it scrolls', () => {
        // Entry height (text-sm line-height 18px): 52-54px below 2xl, 60-62px at 2xl (py-3).
        // max-h-64 (256px) fits exactly 4 working entries at 2xl, hiding the 5th entirely
        // on overlay-scrollbar systems; max-h-72 (288px) always shows ~26px+ of the 5th entry.
        const wrapper = mount(TeamActivityCard);

        const list = wrapper.get('[data-testid="team_activity_list"]');
        expect(list.classes()).toContain('max-h-72');
    });
});
