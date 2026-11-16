<script setup lang="ts">
import { CircleAlert, CircleCheck, Clock } from '@lucide/vue';
import { computed } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import type { GenerationStatus } from '@/types';

const props = defineProps<{ status: GenerationStatus; retrying?: boolean }>();

const appearance = computed(() => {
    switch (props.status) {
        case 'pending':
            return {
                label: props.retrying ? 'Retrying' : 'Submitting',
                classes:
                    'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
            };
        case 'queued':
            return {
                label: 'Queued',
                classes:
                    'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-300',
            };
        case 'processing':
            return {
                label: 'Processing',
                classes:
                    'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
            };
        case 'succeeded':
            return {
                label: 'Succeeded',
                classes:
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
            };
        default:
            return {
                label: 'Failed',
                classes:
                    'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
            };
    }
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
        :class="appearance.classes"
    >
        <Clock v-if="status === 'queued'" class="size-3" />
        <Spinner
            v-else-if="status === 'pending' || status === 'processing'"
            class="size-3"
        />
        <CircleCheck v-else-if="status === 'succeeded'" class="size-3" />
        <CircleAlert v-else class="size-3" />
        {{ appearance.label }}
    </span>
</template>
