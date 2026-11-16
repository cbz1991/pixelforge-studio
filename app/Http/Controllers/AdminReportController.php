<?php

namespace App\Http\Controllers;

use App\Reports\SpendReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    /** Pixelforge's /v1/usage accepts at most 92 days. */
    private const MAX_DAYS = 92;

    public function __invoke(Request $request, SpendReport $report): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : Date::now()->startOfDay();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : $to->subDays(29);

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        return Inertia::render('admin/Report', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'report' => $report->build($from, $to),
        ]);
    }
}
