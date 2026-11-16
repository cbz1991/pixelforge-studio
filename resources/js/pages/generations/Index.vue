<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationForm from '@/components/generations/GenerationForm.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { Generation, Paginated, Pricing } from '@/types';

const props = defineProps<{
    generations: Paginated<Generation>;
    pricing: Pricing | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Studio', href: dashboard() }],
    },
});

const hasUnfinished = computed(() =>
    props.generations.data.some(
        (generation) => !['succeeded', 'failed'].includes(generation.status),
    ),
);

// Refresh statuses while anything is still running; stop once all are final.
const { start, stop } = usePoll(
    2000,
    { only: ['generations'] },
    { autoStart: false },
);

watch(hasUnfinished, (running) => (running ? start() : stop()), {
    immediate: true,
});
</script>

<template>
    <Head title="Studio" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-8 p-4 md:p-6">
        <section>
            <Heading
                title="Generate an image"
                description="Describe what you want. You'll see the estimated cost before you submit."
            />
            <GenerationForm :pricing="pricing" />
        </section>

        <section>
            <Heading
                variant="small"
                title="Your generations"
                :description="
                    generations.meta.total
                        ? `${generations.meta.total} so far`
                        : undefined
                "
            />

            <div
                v-if="generations.data.length === 0"
                class="mt-4 rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
            >
                Nothing here yet. Your images will show up here as they're
                generated.
            </div>

            <div v-else class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>

            <nav
                v-if="generations.meta.last_page > 1"
                class="mt-6 flex items-center justify-between text-sm"
            >
                <Button
                    v-if="generations.links.prev"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="generations.links.prev">Newer</Link>
                </Button>
                <span v-else />
                <span class="text-muted-foreground">
                    Page {{ generations.meta.current_page }} of
                    {{ generations.meta.last_page }}
                </span>
                <Button
                    v-if="generations.links.next"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="generations.links.next">Older</Link>
                </Button>
                <span v-else />
            </nav>
        </section>
    </div>
</template>
