<script setup lang="ts">
import { ref } from 'vue';
import { PlusIcon } from '@heroicons/vue/20/solid';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import ProjectMemberTable from '@/Components/Common/ProjectMember/ProjectMemberTable.vue';
import ProjectMemberCreateModal from '@/Components/Common/ProjectMember/ProjectMemberCreateModal.vue';
import type { ProjectMember } from '@/packages/api/src';

const show = defineModel<boolean>('show', { default: false });

defineProps<{
    projectId: string;
    projectMembers: ProjectMember[];
}>();

const createProjectMember = ref(false);
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false">
        <template #title>
            <div class="flex items-center justify-between">
                <span>Project Members</span>
                <SecondaryButton :icon="PlusIcon" @click="createProjectMember = true">
                    Add Member
                </SecondaryButton>
            </div>
        </template>

        <template #content>
            <ProjectMemberTable
                :project-members="projectMembers"
                :project-id="projectId"></ProjectMemberTable>
            <ProjectMemberCreateModal
                v-model:show="createProjectMember"
                :project-id="projectId"
                :existing-members="projectMembers"></ProjectMemberCreateModal>
        </template>

        <template #footer>
            <SecondaryButton @click="show = false">Close</SecondaryButton>
        </template>
    </DialogModal>
</template>
