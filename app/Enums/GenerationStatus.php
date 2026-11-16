<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Saved by us, not yet accepted by Pixelforge (submitting or waiting to retry). */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Statuses only ever move forward. Webhooks can arrive late or out of
     * order, so e.g. a retried "processing" after "succeeded" must be ignored.
     */
    public function canMoveTo(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
