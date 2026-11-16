<?php

namespace App\Http\Controllers;

use App\Actions\Generations\StartGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Pixelforge\PixelforgeException;
use App\Pixelforge\PricingRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request, PricingRepository $pricing): Response
    {
        $generations = $request->user()->generations()->latest('id')->paginate(24);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            // Only needed for the up-front estimate, so skip it when just polling statuses.
            'pricing' => fn () => $this->pricing($pricing),
        ]);
    }

    public function store(StoreGenerationRequest $request, StartGeneration $startGeneration): RedirectResponse
    {
        try {
            $startGeneration(
                $request->user(),
                $request->string('prompt')->value(),
                $request->size(),
                $request->quality(),
                $request->referenceImages(),
            );
        } catch (PixelforgeException) {
            return back()->withErrors(['prompt' => 'Pixelforge pricing is unavailable right now, so we can\'t price this generation. Please try again shortly.']);
        }

        return to_route('dashboard');
    }

    /** @return array<string, mixed>|null */
    private function pricing(PricingRepository $pricing): ?array
    {
        try {
            return [...$pricing->current()->toArray(), 'markup_percent' => (int) config('billing.markup_percent')];
        } catch (PixelforgeException) {
            return null;
        }
    }
}
