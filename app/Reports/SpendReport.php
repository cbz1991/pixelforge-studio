<?php

namespace App\Reports;

use App\Billing\Nanodollars;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What users were charged, what Pixelforge charged us, and the difference,
 * per UTC day and per user.
 *
 * Days are the day a generation *completed*, in UTC, because that's the day
 * Pixelforge bills it on. Only succeeded generations cost anything; failed
 * ones are recorded at zero on both sides. Amounts are integer nanodollars.
 */
class SpendReport
{
    public function __construct(private readonly PixelforgeClient $client) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byDay = $this->aggregate($from, $to, [DB::raw('DATE(completed_at) as day')], ['day'])->keyBy('day');
        $usage = $this->pixelforgeUsage($from, $to);

        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $date) {
            $day = $date->toDateString();
            $row = $byDay->get($day);
            $providerCost = (int) ($row->provider_cost ?? 0);
            $reported = $usage['days'][$day] ?? null;

            $days[] = [
                'date' => $day,
                'generations' => (int) ($row->generations ?? 0),
                'credit_spend' => (int) ($row->credit_spend ?? 0),
                'provider_cost' => $providerCost,
                'profit' => (int) ($row->credit_spend ?? 0) - $providerCost,
                'pixelforge_generations' => $reported['generations'] ?? null,
                'pixelforge_cost' => $reported['cost'] ?? null,
                // Positive: we recorded more than Pixelforge billed; negative: less.
                'difference' => $reported === null ? null : $providerCost - $reported['cost'],
            ];
        }

        $users = $this->aggregate($from, $to, ['users.id as user_id', 'users.name', 'users.email'], ['users.id', 'users.name', 'users.email'])
            ->sortByDesc('credit_spend')
            ->map(fn ($row) => $this->money($row, ['name' => $row->name, 'email' => $row->email]))
            ->values()
            ->all();

        $daysByUser = $this->aggregate(
            $from,
            $to,
            [DB::raw('DATE(completed_at) as day'), 'users.name', 'users.email'],
            ['day', 'users.id', 'users.name', 'users.email'],
        )
            ->sortBy([['day', 'desc'], ['credit_spend', 'desc']])
            ->map(fn ($row) => $this->money($row, ['date' => $row->day, 'name' => $row->name, 'email' => $row->email]))
            ->values()
            ->all();

        $totals = [
            'generations' => array_sum(array_column($days, 'generations')),
            'credit_spend' => array_sum(array_column($days, 'credit_spend')),
            'provider_cost' => array_sum(array_column($days, 'provider_cost')),
        ];
        $totals['profit'] = $totals['credit_spend'] - $totals['provider_cost'];
        $totals['pixelforge_cost'] = $usage['total'];
        $totals['difference'] = $usage['total'] === null ? null : $totals['provider_cost'] - $usage['total'];

        return [
            'days' => array_reverse($days), // newest first
            'users' => $users,
            'daysByUser' => $daysByUser,
            'totals' => $totals,
            'pixelforgeError' => $usage['error'],
        ];
    }

    /**
     * @param  list<mixed>  $select
     * @param  list<string>  $groupBy
     * @return Collection<int, \stdClass>
     */
    private function aggregate(CarbonImmutable $from, CarbonImmutable $to, array $select, array $groupBy)
    {
        return Generation::query()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('status', GenerationStatus::Succeeded)
            ->where('completed_at', '>=', $from->startOfDay())
            ->where('completed_at', '<', $to->addDay()->startOfDay())
            ->select([
                ...$select,
                DB::raw('COUNT(*) as generations'),
                DB::raw('SUM(price) as credit_spend'),
                DB::raw('SUM(provider_cost) as provider_cost'),
            ])
            ->groupBy($groupBy)
            ->toBase()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function money(\stdClass $row, array $extra): array
    {
        $creditSpend = (int) $row->credit_spend;
        $providerCost = (int) $row->provider_cost;

        return [
            ...$extra,
            'generations' => (int) $row->generations,
            'credit_spend' => $creditSpend,
            'provider_cost' => $providerCost,
            'profit' => $creditSpend - $providerCost,
        ];
    }

    /**
     * Pixelforge's own record of what it billed this API key, per UTC day.
     *
     * @return array{days: array<string, array{generations: int, cost: int}>, total: int|null, error: string|null}
     */
    private function pixelforgeUsage(CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $response = Cache::remember(
                "pixelforge:usage:{$from->toDateString()}:{$to->toDateString()}",
                60,
                fn () => $this->client->usage($from->toDateString(), $to->toDateString()),
            );
        } catch (PixelforgeException $e) {
            return ['days' => [], 'total' => null, 'error' => $e->getMessage()];
        }

        $days = [];
        foreach ((array) ($response['data'] ?? []) as $day) {
            $days[(string) $day['date']] = [
                'generations' => (int) $day['generations'],
                'cost' => Nanodollars::fromDecimal((string) $day['cost_usd']),
            ];
        }

        return [
            'days' => $days,
            'total' => isset($response['total']['cost_usd']) ? Nanodollars::fromDecimal((string) $response['total']['cost_usd']) : null,
            'error' => null,
        ];
    }
}
