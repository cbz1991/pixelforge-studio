<?php

namespace App\Pixelforge;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Current Pixelforge pricing, cached so estimates don't call the API on every
 * page view. If Pixelforge can't be reached we fall back to the last pricing we
 * saw, since estimates are only estimates; the actual charge always uses the
 * rates stored on each generation.
 */
class PricingRepository
{
    private const FRESH_KEY = 'pixelforge:pricing';

    private const LAST_KNOWN_KEY = 'pixelforge:pricing:last-known';

    private const TTL_SECONDS = 300;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly Cache $cache,
    ) {}

    public function current(): PricingTable
    {
        $cached = $this->cache->get(self::FRESH_KEY);

        if (is_array($cached)) {
            return PricingTable::fromArray($cached);
        }

        try {
            $pricing = $this->client->pricing();
        } catch (PixelforgeException $e) {
            $lastKnown = $this->cache->get(self::LAST_KNOWN_KEY);

            if (! is_array($lastKnown)) {
                throw $e;
            }

            Log::warning('Using last known Pixelforge pricing.', ['error' => $e->getMessage()]);

            return PricingTable::fromArray($lastKnown);
        }

        $this->cache->put(self::FRESH_KEY, $pricing->toArray(), self::TTL_SECONDS);
        $this->cache->forever(self::LAST_KNOWN_KEY, $pricing->toArray());

        return $pricing;
    }
}
