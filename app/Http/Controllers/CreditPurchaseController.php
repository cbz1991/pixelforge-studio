<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Billing\Nanodollars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * A pretend checkout: picking a pack adds the credits straight away.
 */
class CreditPurchaseController extends Controller
{
    /** Packs on offer, in whole US dollars. */
    public const PACKS = [5, 10, 25];

    public function __invoke(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'dollars' => ['required', 'integer', Rule::in(self::PACKS)],
        ]);

        $amount = (int) $validated['dollars'] * Nanodollars::PER_DOLLAR;
        $ledger->purchase($request->user(), $amount);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Added '.Nanodollars::formatUsd($amount).' in credits.']);

        return back();
    }
}
