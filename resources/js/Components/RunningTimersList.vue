<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import dayjs from 'dayjs';
import { ArrowsRightLeftIcon, StopIcon } from '@heroicons/vue/20/solid';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { formatDuration } from '@/packages/ui/src/utils/time';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';

const store = useCurrentTimeEntryStore();
const { backgroundTimeEntries, now } = storeToRefs(store);
const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();

const rows = computed(() =>
    backgroundTimeEntries.value.map((timeEntry) => {
        const project = projects.value.find((candidate) => candidate.id === timeEntry.project_id);
        const task = tasks.value.find((candidate) => candidate.id === timeEntry.task_id);
        const seconds = now.value ? Math.max(0, now.value.diff(dayjs(timeEntry.start), 's')) : 0;
        return {
            id: timeEntry.id,
            description: timeEntry.description,
            projectName: project?.name ?? 'No project',
            projectColor: project?.color ?? null,
            taskName: task?.name ?? null,
            duration: formatDuration(seconds),
        };
    })
);

function stop(timeEntryId: string) {
    store.stopBackgroundTimer(timeEntryId, { offerPlaneReview: true });
}

function switchTo(timeEntryId: string) {
    store.focusTimer(timeEntryId);
}
</script>

<template>
    <div v-if="rows.length > 0" class="mt-3 space-y-2" data-testid="running_timers">
        <div class="text-xs font-medium text-text-secondary">Running timers</div>
        <div
            v-for="row in rows"
            :key="row.id"
            class="flex items-center gap-3 rounded-lg border border-card-border bg-card-background px-3 py-2 text-sm">
            <div class="min-w-0 flex-1">
                <div class="flex min-w-0 items-center gap-2 text-text-primary">
                    <span
                        v-if="row.projectColor"
                        class="inline-block h-2 w-2 shrink-0 rounded-full"
                        :style="{ backgroundColor: row.projectColor }"></span>
                    <span class="truncate font-medium">{{ row.projectName }}</span>
                    <span v-if="row.taskName" class="truncate text-text-secondary">
                        {{ row.taskName }}
                    </span>
                </div>
                <div class="truncate text-xs text-text-secondary">
                    {{ row.description !== '' ? row.description : 'No description' }}
                </div>
            </div>
            <span class="shrink-0 font-medium tabular-nums text-text-primary">
                {{ row.duration }}
            </span>
            <SecondaryButton size="small" :icon="ArrowsRightLeftIcon" @click="switchTo(row.id)">
                Switch to
            </SecondaryButton>
            <SecondaryButton
                size="small"
                :icon="StopIcon"
                class="text-red-400 hover:text-red-500"
                @click="stop(row.id)">
                Stop
            </SecondaryButton>
        </div>
    </div>
</template>
