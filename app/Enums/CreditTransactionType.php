<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    /** Credits bought by the user. */
    case Purchase = 'purchase';
    /** The most a generation can cost, set aside when it's submitted. */
    case Hold = 'hold';
    /** The hold given back once the generation finishes, either way. */
    case HoldRelease = 'hold_release';
    /** What a succeeded generation actually cost. */
    case Charge = 'charge';
}
