<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    max: number;
    /** Validation errors keyed like "reference_images.0". */
    errors: Partial<Record<string, string>>;
}>();

const urls = defineModel<string[]>({ required: true });

function add(): void {
    if (urls.value.length < props.max) {
        urls.value = [...urls.value, ''];
    }
}

function update(position: number, event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    urls.value = urls.value.map((url, i) => (i === position ? value : url));
}

function remove(position: number): void {
    urls.value = urls.value.filter((_, i) => i !== position);
}

function errorFor(position: number): string | undefined {
    return props.errors[`reference_images.${position}`];
}
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-baseline justify-between">
            <Label>
                Reference images
                <span class="font-normal text-muted-foreground">
                    (optional, 765 tokens each)
                </span>
            </Label>
            <span class="text-xs text-muted-foreground">
                {{ urls.length }} / {{ max }}
            </span>
        </div>

        <div v-for="(url, position) in urls" :key="position" class="grid gap-1">
            <div class="flex gap-2">
                <!-- A native input: vue-tsc loses the v-for scope on the ui/Input component's update event. -->
                <input
                    :value="url"
                    type="url"
                    inputmode="url"
                    placeholder="https://example.com/style.png"
                    :aria-invalid="!!errorFor(position)"
                    class="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                    @input="update(position, $event)"
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Remove reference image"
                    @click="remove(position)"
                >
                    <X />
                </Button>
            </div>
            <InputError :message="errorFor(position)" />
        </div>

        <InputError :message="errors.reference_images" />

        <Button
            v-if="urls.length < max"
            type="button"
            variant="outline"
            size="sm"
            class="w-fit"
            @click="add"
        >
            <Plus /> Add reference image
        </Button>
    </div>
</template>
