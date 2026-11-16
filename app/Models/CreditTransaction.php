<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $generation_id
 * @property CreditTransactionType $type
 * @property int $amount signed nanodollars
 * @property CarbonImmutable $created_at
 */
class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
