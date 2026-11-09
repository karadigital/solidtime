import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import UpdateProfileInformationForm from './UpdateProfileInformationForm.vue';

const { fixtureUser, mutateAsync } = vi.hoisted(() => ({
    fixtureUser: {
        id: 'user-1',
        name: 'Jane Doe',
        email: 'jane@example.com',
        pending_email: null,
        profile_photo_url: 'https://ui-avatars.com/api/?name=J+D',
        timezone: 'Asia/Jakarta',
        week_start: 'monday',
        auto_stop_time_entries: false,
        auto_stop_time: '17:30',
    },
    mutateAsync: vi.fn(),
}));

vi.mock('@/utils/useUserQuery', async () => {
    const { ref } = await import('vue');
    return {
        useUserQuery: () => ({ user: ref(fixtureUser) }),
        useUpdateUserMutation: () => ({ mutateAsync, isPending: ref(false), error: ref(null) }),
        useResendUserEmailVerificationMutation: () => ({
            mutateAsync: vi.fn(),
            isPending: ref(false),
        }),
        useResetUserPendingEmailMutation: () => ({ mutateAsync: vi.fn(), isPending: ref(false) }),
    };
});
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            timezones: { 'Asia/Jakarta': 'Asia/Jakarta' },
            weekdays: { monday: 'Monday' },
        },
    }),
}));

describe('UpdateProfileInformationForm auto stop settings', () => {
    beforeEach(() => {
        mutateAsync.mockReset();
        mutateAsync.mockResolvedValue(fixtureUser);
    });

    it('disables the stop time input while auto stop is off', () => {
        const wrapper = mount(UpdateProfileInformationForm);

        expect(wrapper.get('#auto_stop_time').attributes('disabled')).toBeDefined();
    });

    it('sends the auto stop settings when they are changed', async () => {
        const wrapper = mount(UpdateProfileInformationForm);

        await wrapper.get('#auto_stop_time_entries').setValue(true);
        await wrapper.get('#auto_stop_time').setValue('16:00');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(mutateAsync).toHaveBeenCalledWith({
            userId: 'user-1',
            body: { auto_stop_time_entries: true, auto_stop_time: '16:00' },
        });
    });

    it('does not send auto stop settings when unchanged', async () => {
        const wrapper = mount(UpdateProfileInformationForm);

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(mutateAsync).not.toHaveBeenCalled();
    });
});
