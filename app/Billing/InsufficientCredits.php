<?php

namespace App\Billing;

use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $required, public readonly int $balance)
    {
        parent::__construct('Not enough credits for this generation.');
    }
}
