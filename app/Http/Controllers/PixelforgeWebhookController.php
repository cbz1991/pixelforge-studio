<?php

namespace App\Http\Controllers;

use App\Actions\Generations\ApplyPixelforgeUpdate;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, ApplyPixelforgeUpdate $applyUpdate): Response
    {
        // Verify against the raw body, before any JSON decoding.
        $payload = $request->getContent();

        if (! $signature->isValid($payload, (string) $request->header('Pixelforge-Signature'), Date::now()->getTimestamp())) {
            Log::warning('Rejected a Pixelforge webhook with an invalid signature.');

            return response('Invalid signature.', 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response('Malformed event.', 400);
        }

        // Recording the event and applying it happen in one transaction: if applying
        // fails we answer 500, nothing is recorded, and Pixelforge's retry starts over.
        DB::transaction(function () use ($request, $event, $applyUpdate): void {
            $object = $event['data']['object'] ?? null;

            $isNew = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'pixelforge_generation_id' => is_array($object) ? ($object['id'] ?? null) : null,
                'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt') ?: null,
                'payload' => json_encode($event),
                'received_at' => Date::now(),
            ]) === 1;

            if (! $isNew) {
                return; // Already handled this event.
            }

            if (str_starts_with($event['type'], 'generation.') && is_array($object)) {
                $applyUpdate($object);
            }
        });

        return response()->noContent();
    }
}
