<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { formatUsd } from '@/lib/money';
import { purchase } from '@/routes/credits';

defineProps<{
    /** Nanodollars. */
    balance: number;
    /** Whole US dollars. */
    packs: number[];
}>();

const checkout = useForm({ dollars: 0 });

function buy(dollars: number): void {
    checkout.dollars = dollars;
    checkout.submit(purchase(), { preserveScroll: true });
}
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border"
    >
        <div class="flex items-center gap-3">
            <div
                class="flex size-9 items-center justify-center rounded-lg bg-muted"
            >
                <Wallet class="size-4" />
            </div>
            <div>
                <p class="text-xs text-muted-foreground">Balance</p>
                <p
                    class="text-lg font-semibold tabular-nums"
                    :class="balance < 0 ? 'text-red-600' : ''"
                >
                    {{ formatUsd(balance) }}
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-muted-foreground">Buy credits:</span>
            <Button
                v-for="pack in packs"
                :key="pack"
                variant="outline"
                size="sm"
                :disabled="checkout.processing"
                @click="buy(pack)"
            >
                +${{ pack }}
            </Button>
        </div>
    </div>
</template>
