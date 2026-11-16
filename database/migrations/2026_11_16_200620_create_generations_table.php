<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money columns are integer nanodollars (1 USD = 1,000,000,000).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            // Our own id: sent as the Idempotency-Key and in metadata, so a webhook
            // can be matched even if it arrives before the POST has returned.
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // What we told the user up front (with markup).
            $table->unsignedBigInteger('estimated_price');
            $table->unsignedBigInteger('maximum_price');

            // Known once Pixelforge reports the generation succeeded.
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->unsignedBigInteger('provider_cost')->nullable();
            $table->unsignedBigInteger('price')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedSmallInteger('submit_attempts')->default(0);
            // Pixelforge's own timestamps; completed_at decides the billing day.
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
