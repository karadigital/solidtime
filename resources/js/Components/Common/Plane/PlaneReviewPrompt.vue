<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import { api } from '@/packages/api/src';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useNotificationsStore } from '@/utils/notification';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { getPlaneIdentifier } from '@/utils/plane';

const { planeReviewCandidate } = storeToRefs(useCurrentTimeEntryStore());
const { tasks, isSuccess } = useTasksQuery();
const { addNotification, handleApiRequestNotifications } = useNotificationsStore();

const task = computed(() => {
    const candidate = planeReviewCandidate.value;
    if (candidate === null) return undefined;
    return tasks.value.find((t) => t.id === candidate.taskId);
});
const identifier = computed(() => (task.value ? getPlaneIdentifier(task.value.name) : null));
const isOpen = computed(() => planeReviewCandidate.value !== null && identifier.value !== null);

// Tasks that are not Plane work items never show the prompt
watch(
    [planeReviewCandidate, task, isSuccess],
    () => {
        if (planeReviewCandidate.value !== null && isSuccess.value && identifier.value === null) {
            planeReviewCandidate.value = null;
        }
    },
    { immediate: true }
);

// Snapshot of what the open dialog is about: the dialog's action button closes the dialog (and clears the
// candidate) before its click handler runs.
const prompted = ref<{ timeEntryId: string; identifier: string } | null>(null);
watch(isOpen, (open) => {
    if (open && planeReviewCandidate.value !== null && identifier.value !== null) {
        prompted.value = {
            timeEntryId: planeReviewCandidate.value.timeEntryId,
            identifier: identifier.value,
        };
    }
});

function close() {
    planeReviewCandidate.value = null;
}

async function moveToReview() {
    const target = prompted.value;
    const organization = getCurrentOrganizationId();
    prompted.value = null;
    close();
    if (target === null || organization === null) return;

    const response = await handleApiRequestNotifications(() =>
        api.moveTimeEntryTaskToPlaneReview(undefined, {
            params: { organization, timeEntry: target.timeEntryId },
        })
    );
    const result = response?.data;
    if (!result) return;

    switch (result.outcome) {
        case 'moved':
            addNotification('success', `Moved ${result.identifier} to ${result.state}`);
            break;
        case 'already_in_state':
            addNotification('success', `${result.identifier} is already in ${result.state}`);
            break;
        case 'work_item_closed':
            addNotification('error', `${result.identifier} is already done or cancelled in Plane`);
            break;
        case 'state_not_found':
            addNotification(
                'error',
                `${result.identifier}'s project has no "In Review" or "Ready for Test" state`
            );
            break;
        case 'work_item_not_found':
            addNotification('error', `${result.identifier} was not found in Plane`);
            break;
    }
}
</script>

<template>
    <AlertDialog :open="isOpen" @update:open="(open: boolean) => !open && close()">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Move {{ identifier }} to review?</AlertDialogTitle>
                <AlertDialogDescription>
                    Your timer for "{{ task?.name }}" was stopped. Move the Plane work item to
                    review, or keep its current status if you are not done yet.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel @click="close">Not yet</AlertDialogCancel>
                <AlertDialogAction @click="moveToReview">Move to review</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
