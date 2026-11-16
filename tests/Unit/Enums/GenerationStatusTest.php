<?php

use App\Enums\GenerationStatus as Status;

it('only moves forward', function (Status $from, Status $to, bool $allowed) {
    expect($from->canMoveTo($to))->toBe($allowed);
})->with([
    [Status::Pending, Status::Queued, true],
    [Status::Pending, Status::Succeeded, true], // webhook before our POST returned
    [Status::Queued, Status::Processing, true],
    [Status::Processing, Status::Succeeded, true],
    [Status::Processing, Status::Failed, true],
    [Status::Processing, Status::Queued, false],
    [Status::Queued, Status::Queued, false],
    [Status::Succeeded, Status::Processing, false], // late retried webhook
    [Status::Succeeded, Status::Failed, false],
    [Status::Failed, Status::Succeeded, false],
]);
