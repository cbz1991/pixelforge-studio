<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatUsd } from '@/lib/money';
import { report as reportRoute } from '@/routes/admin';

type Money = {
    generations: number;
    credit_spend: number;
    provider_cost: number;
    profit: number;
};

type DayRow = Money & {
    date: string;
    pixelforge_generations: number | null;
    pixelforge_cost: number | null;
    difference: number | null;
};

type UserRow = Money & { name: string; email: string };
type DayUserRow = UserRow & { date: string };

const props = defineProps<{
    from: string;
    to: string;
    report: {
        days: DayRow[];
        users: UserRow[];
        daysByUser: DayUserRow[];
        totals: Money & {
            pixelforge_cost: number | null;
            difference: number | null;
        };
        pixelforgeError: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Spend report', href: reportRoute() }],
    },
});

const range = ref({ from: props.from, to: props.to });

function apply(): void {
    router.get(
        reportRoute.url({ query: range.value }),
        {},
        { preserveScroll: true },
    );
}

function differenceLabel(difference: number): string {
    return `${difference > 0 ? '+' : ''}${formatUsd(difference)}`;
}
</script>

<template>
    <Head title="Spend report" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-8 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between"
        >
            <Heading
                title="Spend report"
                description="By UTC day the generation completed, which is the day Pixelforge bills it."
            />
            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="apply"
            >
                <div class="grid gap-1">
                    <Label for="from" class="text-xs">From</Label>
                    <Input
                        id="from"
                        v-model="range.from"
                        type="date"
                        class="w-40"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="to" class="text-xs">To</Label>
                    <Input
                        id="to"
                        v-model="range.to"
                        type="date"
                        class="w-40"
                    />
                </div>
                <Button type="submit" variant="outline">Apply</Button>
            </form>
        </div>

        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border p-4">
                <dt class="text-xs text-muted-foreground">Credit spend</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ formatUsd(report.totals.credit_spend) }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    {{ report.totals.generations }} generations
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-xs text-muted-foreground">
                    Provider cost (our records)
                </dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ formatUsd(report.totals.provider_cost) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-xs text-muted-foreground">Profit</dt>
                <dd
                    class="text-xl font-semibold text-emerald-700 tabular-nums dark:text-emerald-400"
                >
                    {{ formatUsd(report.totals.profit) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-xs text-muted-foreground">
                    Pixelforge says (/v1/usage)
                </dt>
                <template v-if="report.totals.pixelforge_cost !== null">
                    <dd class="text-xl font-semibold tabular-nums">
                        {{ formatUsd(report.totals.pixelforge_cost) }}
                    </dd>
                    <dd
                        v-if="report.totals.difference === 0"
                        class="flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"
                    >
                        <CircleCheck class="size-3" /> Matches our records
                        exactly
                    </dd>
                    <dd
                        v-else
                        class="flex items-center gap-1 text-xs text-red-600"
                    >
                        <CircleAlert class="size-3" />
                        Off by
                        {{ differenceLabel(report.totals.difference ?? 0) }}
                    </dd>
                </template>
                <dd v-else class="text-sm text-amber-700 dark:text-amber-400">
                    Unavailable: {{ report.pixelforgeError }}
                </dd>
            </div>
        </dl>

        <section>
            <Heading variant="small" title="By day" />
            <div class="mt-3 overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">Date (UTC)</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Pixelforge billed
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Reconciled
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y tabular-nums">
                        <tr
                            v-for="day in report.days"
                            :key="day.date"
                            :class="
                                day.generations === 0 && !day.pixelforge_cost
                                    ? 'text-muted-foreground'
                                    : ''
                            "
                        >
                            <td class="px-3 py-2">{{ day.date }}</td>
                            <td class="px-3 py-2 text-right">
                                {{ day.generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(day.credit_spend) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(day.provider_cost) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(day.profit) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <template v-if="day.pixelforge_cost !== null">
                                    {{ formatUsd(day.pixelforge_cost) }}
                                    <span class="text-xs text-muted-foreground">
                                        ({{ day.pixelforge_generations }})
                                    </span>
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >–</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right">
                                <span
                                    v-if="day.difference === 0"
                                    class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"
                                >
                                    <CircleCheck class="size-3.5" /> Exact
                                </span>
                                <span
                                    v-else-if="day.difference !== null"
                                    class="inline-flex items-center gap-1 font-medium text-red-600"
                                >
                                    <CircleAlert class="size-3.5" />
                                    {{ differenceLabel(day.difference) }}
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >–</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <Heading variant="small" title="By user" />
            <div class="mt-3 overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">User</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y tabular-nums">
                        <tr v-for="user in report.users" :key="user.email">
                            <td class="px-3 py-2">
                                {{ user.name }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ user.email }}</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ user.generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(user.credit_spend) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(user.provider_cost) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(user.profit) }}
                            </td>
                        </tr>
                        <tr v-if="report.users.length === 0">
                            <td
                                colspan="5"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                No completed generations in this range.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <Heading variant="small" title="By day and user" />
            <div class="mt-3 overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">Date (UTC)</th>
                            <th class="px-3 py-2 font-medium">User</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y tabular-nums">
                        <tr
                            v-for="row in report.daysByUser"
                            :key="`${row.date}-${row.email}`"
                        >
                            <td class="px-3 py-2">{{ row.date }}</td>
                            <td class="px-3 py-2">{{ row.email }}</td>
                            <td class="px-3 py-2 text-right">
                                {{ row.generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(row.credit_spend) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(row.provider_cost) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(row.profit) }}
                            </td>
                        </tr>
                        <tr v-if="report.daysByUser.length === 0">
                            <td
                                colspan="6"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                No completed generations in this range.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
