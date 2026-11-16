<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import ReferenceImagesInput from '@/components/generations/ReferenceImagesInput.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { countCharacters, estimate } from '@/lib/estimate';
import { formatUsd } from '@/lib/money';
import { cn } from '@/lib/utils';
import { store } from '@/routes/generations';
import type { Pricing, Quality, Size } from '@/types';

const props = defineProps<{ pricing: Pricing | null }>();

const MAX_PROMPT = 4000;
const MAX_REFERENCES = 4;

const sizes: { value: Size; label: string }[] = [
    { value: '1024x1024', label: 'Square' },
    { value: '1536x1024', label: 'Landscape' },
    { value: '1024x1536', label: 'Portrait' },
];
const qualities: Quality[] = ['low', 'medium', 'high'];
const testKeywords = [
    '[succeed]',
    '[fail]',
    '[slow]',
    '[duplicate]',
    '[overloaded]',
    '[blocked]',
];

const draft = useForm({
    prompt: '',
    size: '1024x1024' as Size,
    quality: 'medium' as Quality,
    reference_images: [] as string[],
});

const errors = computed(() => draft.errors as Partial<Record<string, string>>);

const promptLength = computed(() => countCharacters(draft.prompt));

const cost = computed(() => {
    if (!props.pricing || draft.prompt.trim() === '') {
        return null;
    }

    const references = draft.reference_images.filter(
        (reference) => reference.trim() !== '',
    );

    return estimate(
        props.pricing,
        draft.prompt,
        draft.size,
        draft.quality,
        references.length,
    );
});

function addKeyword(keyword: string): void {
    draft.prompt = `${draft.prompt.trimEnd()} ${keyword}`.trimStart();
}

function submit(): void {
    draft.submit(store(), {
        preserveScroll: true,
        onSuccess: () => draft.reset('prompt', 'reference_images'),
    });
}

function optionClasses(selected: boolean): string {
    return cn(
        'rounded-md px-2 py-1.5 text-xs font-medium capitalize transition',
        selected
            ? 'bg-background shadow-sm'
            : 'text-muted-foreground hover:text-foreground',
    );
}
</script>

<template>
    <form
        class="flex flex-col gap-5 rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border"
        @submit.prevent="submit"
    >
        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label for="prompt">Prompt</Label>
                <span
                    class="text-xs tabular-nums"
                    :class="
                        promptLength > MAX_PROMPT
                            ? 'text-red-600'
                            : 'text-muted-foreground'
                    "
                >
                    {{ promptLength.toLocaleString() }} /
                    {{ MAX_PROMPT.toLocaleString() }}
                </span>
            </div>
            <textarea
                id="prompt"
                v-model="draft.prompt"
                rows="4"
                required
                placeholder="A lighthouse on a cliff at dawn, oil painting"
                class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                :aria-invalid="!!errors.prompt"
                @keydown.meta.enter="submit"
                @keydown.ctrl.enter="submit"
            />
            <InputError :message="errors.prompt" />
            <p
                class="flex flex-wrap items-center gap-1 text-xs text-muted-foreground"
            >
                Test keywords:
                <button
                    v-for="keyword in testKeywords"
                    :key="keyword"
                    type="button"
                    class="rounded bg-muted px-1.5 py-0.5 font-mono hover:bg-accent hover:text-accent-foreground"
                    @click="addKeyword(keyword)"
                >
                    {{ keyword }}
                </button>
            </p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <fieldset class="grid gap-2">
                <legend class="mb-2 text-sm font-medium">Size</legend>
                <div class="grid grid-cols-3 gap-1 rounded-lg bg-muted p-1">
                    <button
                        v-for="option in sizes"
                        :key="option.value"
                        type="button"
                        :aria-pressed="draft.size === option.value"
                        :class="optionClasses(draft.size === option.value)"
                        @click="draft.size = option.value"
                    >
                        {{ option.label }}
                        <span
                            class="block text-[10px] font-normal text-muted-foreground"
                        >
                            {{ option.value }}
                        </span>
                    </button>
                </div>
                <InputError :message="errors.size" />
            </fieldset>

            <fieldset class="grid gap-2">
                <legend class="mb-2 text-sm font-medium">Quality</legend>
                <div
                    class="grid h-full grid-cols-3 gap-1 rounded-lg bg-muted p-1"
                >
                    <button
                        v-for="option in qualities"
                        :key="option"
                        type="button"
                        :aria-pressed="draft.quality === option"
                        :class="optionClasses(draft.quality === option)"
                        @click="draft.quality = option"
                    >
                        {{ option }}
                    </button>
                </div>
                <InputError :message="errors.quality" />
            </fieldset>
        </div>

        <ReferenceImagesInput
            v-model="draft.reference_images"
            :max="MAX_REFERENCES"
            :errors="errors"
        />

        <div
            class="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="text-sm" aria-live="polite">
                <template v-if="cost">
                    <p>
                        Estimated cost
                        <span class="font-semibold tabular-nums">
                            ≈ {{ formatUsd(cost.expected, { decimals: 4 }) }}
                        </span>
                        <span class="text-muted-foreground tabular-nums">
                            · at most
                            {{
                                formatUsd(cost.maximum, {
                                    decimals: 4,
                                    roundUp: true,
                                })
                            }}
                        </span>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        You pay the exact cost once it finishes. Failed
                        generations are free.
                    </p>
                </template>
                <p
                    v-else-if="!pricing"
                    class="text-amber-700 dark:text-amber-400"
                >
                    Pricing is unavailable right now, so we can't estimate
                    costs.
                </p>
                <p v-else class="text-muted-foreground">
                    Write a prompt to see what it will cost.
                </p>
            </div>

            <Button
                type="submit"
                :disabled="draft.processing || !pricing"
                class="shrink-0"
            >
                <Spinner v-if="draft.processing" />
                <Sparkles v-else />
                Generate
            </Button>
        </div>
    </form>
</template>
