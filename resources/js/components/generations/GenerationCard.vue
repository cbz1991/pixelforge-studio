<script setup lang="ts">
import { ImageOff } from '@lucide/vue';
import { computed } from 'vue';
import StatusBadge from '@/components/generations/StatusBadge.vue';
import { formatUsd } from '@/lib/money';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

const aspect = computed(
    () =>
        ({
            '1024x1024': 'aspect-square',
            '1536x1024': 'aspect-[3/2]',
            '1024x1536': 'aspect-[2/3]',
        })[props.generation.size],
);

const createdAt = computed(() =>
    new Date(props.generation.created_at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }),
);

const isRunning = computed(() =>
    ['pending', 'queued', 'processing'].includes(props.generation.status),
);
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
    >
        <div
            class="relative flex max-h-80 items-center justify-center overflow-hidden bg-muted"
            :class="aspect"
        >
            <a
                v-if="generation.image_url"
                :href="generation.image_url"
                target="_blank"
                rel="noopener"
                class="h-full w-full"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    loading="lazy"
                    class="h-full w-full object-contain"
                />
            </a>
            <div
                v-else-if="isRunning"
                class="h-full w-full animate-pulse bg-gradient-to-br from-muted to-muted-foreground/10"
            />
            <div
                v-else-if="generation.image_expired"
                class="flex flex-col items-center gap-1 text-xs text-muted-foreground"
            >
                <ImageOff class="size-5" />
                Image link expired
            </div>
            <div
                v-else
                class="flex flex-col items-center gap-1 px-4 text-center text-xs text-muted-foreground"
            >
                <ImageOff class="size-5" />
                No image
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge
                    :status="generation.status"
                    :retrying="!!generation.notice"
                />
                <time
                    class="text-xs text-muted-foreground"
                    :datetime="generation.created_at"
                >
                    {{ createdAt }}
                </time>
            </div>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p class="text-xs text-muted-foreground">
                {{ generation.size }} · {{ generation.quality }} quality
                <template v-if="generation.reference_images.length">
                    · {{ generation.reference_images.length }} reference
                    {{
                        generation.reference_images.length === 1
                            ? 'image'
                            : 'images'
                    }}
                </template>
            </p>

            <p
                v-if="generation.notice"
                class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
            >
                {{ generation.notice }}
            </p>
            <p
                v-if="generation.error"
                class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-800 dark:bg-red-500/10 dark:text-red-300"
            >
                {{ generation.error }}
            </p>

            <dl class="mt-auto grid grid-cols-2 gap-2 border-t pt-3 text-xs">
                <div>
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd class="font-medium tabular-nums">
                        ≈
                        {{
                            formatUsd(generation.estimated_price, {
                                decimals: 4,
                            })
                        }}
                    </dd>
                    <dd class="text-muted-foreground tabular-nums">
                        max
                        {{
                            formatUsd(generation.maximum_price, {
                                decimals: 4,
                                roundUp: true,
                            })
                        }}
                    </dd>
                </div>
                <div class="text-right">
                    <dt class="text-muted-foreground">Charged</dt>
                    <dd
                        v-if="generation.price !== null"
                        class="font-semibold tabular-nums"
                        :title="
                            generation.usage
                                ? `${generation.usage.input_text_tokens} text + ${generation.usage.input_image_tokens} image + ${generation.usage.output_tokens} output + ${generation.usage.reasoning_tokens} reasoning tokens`
                                : undefined
                        "
                    >
                        {{ formatUsd(generation.price) }}
                    </dd>
                    <dd v-else class="text-muted-foreground">
                        When it finishes
                    </dd>
                    <dd
                        v-if="generation.status === 'failed'"
                        class="text-muted-foreground"
                    >
                        Failed generations are free
                    </dd>
                </div>
            </dl>
        </div>
    </article>
</template>
