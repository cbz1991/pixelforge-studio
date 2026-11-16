<?php

use App\Pixelforge\WebhookSignature;

$now = 1_762_265_009;
$sign = fn (string $body, int $t, string $secret = 'whsec_test') => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
$verifier = fn () => new WebhookSignature('whsec_test', 300);

it('accepts a correct signature', function () use ($now, $sign, $verifier) {
    expect($verifier()->isValid('{"id":"evt_1"}', $sign('{"id":"evt_1"}', $now), $now))->toBeTrue();
});

it('rejects a tampered body', function () use ($now, $sign, $verifier) {
    expect($verifier()->isValid('{"id":"evt_2"}', $sign('{"id":"evt_1"}', $now), $now))->toBeFalse();
});

it('rejects a signature made with another secret', function () use ($now, $sign, $verifier) {
    expect($verifier()->isValid('{}', $sign('{}', $now, 'whsec_other'), $now))->toBeFalse();
});

it('rejects old timestamps to stop replays', function () use ($now, $sign, $verifier) {
    expect($verifier()->isValid('{}', $sign('{}', $now - 301), $now))->toBeFalse()
        ->and($verifier()->isValid('{}', $sign('{}', $now - 299), $now))->toBeTrue();
});

it('rejects missing or garbled headers', function (string $header) use ($now, $verifier) {
    expect($verifier()->isValid('{}', $header, $now))->toBeFalse();
})->with(['', 'garbage', 't=abc,v1=00', "t={$now}", 'v1=deadbeef']);

it('rejects everything when no secret is configured', function () use ($now, $sign) {
    expect((new WebhookSignature('', 300))->isValid('{}', $sign('{}', $now, ''), $now))->toBeFalse();
});
