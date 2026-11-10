<script setup lang="ts">
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { ArrowTopRightOnSquareIcon } from '@heroicons/vue/20/solid';
import { api, type PlaneWorkItemDetail, type Task } from '@/packages/api/src';
import { buttonVariants } from '@/packages/ui/src/Buttons';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import LoadingSpinner from '@/packages/ui/src/LoadingSpinner.vue';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { getPlaneIdentifier } from '@/utils/plane';

const props = defineProps<{
    task: Task | null;
}>();

const identifier = computed(() => (props.task ? getPlaneIdentifier(props.task.name) : null));

const query = useQuery({
    queryKey: computed(() => ['planeWorkItem', getCurrentOrganizationId(), props.task?.id]),
    queryFn: async (): Promise<PlaneWorkItemDetail> => {
        const response = await api.getTaskPlaneWorkItem({
            params: {
                organization: getCurrentOrganizationId()!,
                task: props.task!.id,
            },
        });
        return response.data;
    },
    enabled: computed(() => !!props.task && identifier.value !== null),
    staleTime: 60_000,
    retry: false,
});

const detail = computed(() => query.data.value ?? null);

const errorStatus = computed(() => {
    const error = query.error.value as { response?: { status?: number } } | null;
    return error?.response?.status ?? null;
});

const dateRange = computed(() => {
    if (!detail.value?.start_date && !detail.value?.target_date) {
        return null;
    }
    return `${detail.value?.start_date ?? '…'} → ${detail.value?.target_date ?? '…'}`;
});
</script>

<template>
    <div class="px-4 py-5 sm:px-6 text-sm" data-testid="plane_work_item_panel">
        <p v-if="!task" class="text-text-secondary">Select a task to see its Plane details.</p>
        <p v-else-if="identifier === null" class="text-text-secondary">
            This task is not linked to a Plane work item.
        </p>
        <div v-else-if="query.isLoading.value" class="flex justify-center py-8">
            <LoadingSpinner></LoadingSpinner>
        </div>
        <div v-else-if="query.isError.value" class="space-y-2">
            <template v-if="errorStatus === 404">
                <p class="font-medium text-text-primary">{{ identifier }} was not found in Plane</p>
                <p class="text-text-secondary">
                    The work item does not exist, or the Plane bot is not a member of its project.
                </p>
            </template>
            <template v-else>
                <p class="text-text-secondary">
                    Plane could not be reached. Please try again later.
                </p>
                <SecondaryButton size="small" @click="query.refetch()">Retry</SecondaryButton>
            </template>
        </div>
        <div v-else-if="detail" class="space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-medium text-text-tertiary">{{ detail.identifier }}</p>
                    <h3 class="text-base font-semibold text-text-primary break-words">
                        {{ detail.name }}
                    </h3>
                </div>
                <a
                    :href="detail.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })">
                    <ArrowTopRightOnSquareIcon></ArrowTopRightOnSquareIcon>
                    Open in Plane
                </a>
            </div>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-text-secondary">
                <template v-if="detail.state">
                    <dt>State</dt>
                    <dd class="flex items-center gap-1.5 text-text-primary">
                        <span
                            class="inline-block h-2 w-2 rounded-full"
                            :style="{ backgroundColor: detail.state.color }"></span>
                        {{ detail.state.name }}
                    </dd>
                </template>
                <dt>Priority</dt>
                <dd class="text-text-primary capitalize">{{ detail.priority }}</dd>
                <template v-if="detail.assignees.length > 0">
                    <dt>Assignees</dt>
                    <dd class="text-text-primary">
                        {{ detail.assignees.map((assignee) => assignee.display_name).join(', ') }}
                    </dd>
                </template>
                <template v-if="detail.labels.length > 0">
                    <dt>Labels</dt>
                    <dd class="flex flex-wrap gap-2 text-text-primary">
                        <span
                            v-for="label in detail.labels"
                            :key="label.name"
                            class="flex items-center gap-1">
                            <span
                                class="inline-block h-2 w-2 rounded-full"
                                :style="{ backgroundColor: label.color }"></span>
                            {{ label.name }}
                        </span>
                    </dd>
                </template>
                <template v-if="dateRange">
                    <dt>Dates</dt>
                    <dd class="text-text-primary">{{ dateRange }}</dd>
                </template>
            </dl>
            <div class="border-t border-card-background-separator pt-4">
                <!-- description_html is sanitized on the server by PlaneDescriptionSanitizer -->
                <!-- eslint-disable vue/no-v-html -->
                <div
                    v-if="detail.description_html !== ''"
                    class="prose prose-sm dark:prose-invert max-w-none break-words"
                    v-html="detail.description_html"></div>
                <!-- eslint-enable vue/no-v-html -->
                <p v-else class="text-text-secondary">No description.</p>
            </div>
        </div>
    </div>
</template>
