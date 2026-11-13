<script setup>
import AppShell from '../../Layouts/AppShell.vue';
import TaskBoard from '../../Todo/TaskBoard.vue';

defineProps({
    timezone: { type: String, required: true },
    today: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
    members: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
    selectedTaskMissing: { type: Boolean, default: false },
    history: { type: Array, default: null },
});
</script>

<template>
    <AppShell current="tasks">
        <TaskBoard :timezone="timezone" :today="today" :can-edit="canEdit" :members="members" :tasks="tasks" :selected-task-missing="selectedTaskMissing" :history="history" />
    </AppShell>
</template>
