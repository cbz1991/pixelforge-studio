<?php

namespace App\Billing;

use App\Enums\CreditTransactionType as Type;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes a user's balance. Every change is a ledger row,
 * written in the same transaction as the balance update.
 */
class CreditLedger
{
    public function purchase(User $user, int $amount): void
    {
        DB::transaction(fn () => $this->record($user->id, null, Type::Purchase, $amount));
    }

    /**
     * Sets aside the most this generation can cost. The balance check and the
     * debit are one conditional UPDATE, so two concurrent submissions can't both
     * spend the same credits.
     *
     * @throws InsufficientCredits
     */
    public function hold(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $debited = User::query()
                ->whereKey($generation->user_id)
                ->where('balance', '>=', $generation->maximum_price)
                ->decrement('balance', $generation->maximum_price);

            if ($debited === 0) {
                throw new InsufficientCredits($generation->maximum_price, (int) User::query()->whereKey($generation->user_id)->value('balance'));
            }

            $this->insert($generation->user_id, $generation->id, Type::Hold, -$generation->maximum_price);
        });
    }

    /**
     * Once a generation is final: give back the hold, and charge the actual
     * price if it succeeded. Safe to call repeatedly; each movement is
     * recorded at most once.
     */
    public function settle(Generation $generation): void
    {
        if (! $generation->status->isFinal()) {
            return;
        }

        DB::transaction(function () use ($generation): void {
            $hold = CreditTransaction::query()
                ->where('generation_id', $generation->id)
                ->where('type', Type::Hold)
                ->first();

            // Only give back what was actually held.
            if ($hold !== null) {
                $this->record($generation->user_id, $generation->id, Type::HoldRelease, -$hold->amount);
            }

            if ($generation->status === GenerationStatus::Succeeded && $generation->price !== null) {
                $this->record($generation->user_id, $generation->id, Type::Charge, -$generation->price);
            }
        });
    }

    /** Adds a ledger row and moves the balance, unless this movement already happened. */
    private function record(int $userId, ?int $generationId, Type $type, int $amount): void
    {
        if ($this->insert($userId, $generationId, $type, $amount)) {
            User::query()->whereKey($userId)->increment('balance', $amount);
        }
    }

    private function insert(int $userId, ?int $generationId, Type $type, int $amount): bool
    {
        return CreditTransaction::query()->insertOrIgnore([
            'user_id' => $userId,
            'generation_id' => $generationId,
            'type' => $type->value,
            'amount' => $amount,
            'created_at' => Date::now(),
        ]) === 1;
    }
}
