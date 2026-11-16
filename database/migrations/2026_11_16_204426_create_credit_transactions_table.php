<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only ledger of credit movements. Amounts are signed integer
 * nanodollars: positive adds to the balance, negative takes from it.
 * users.balance is always the sum of a user's rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('balance')->default(0)->after('password');
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->bigInteger('amount');
            $table->timestamp('created_at');

            // Each kind of movement happens at most once per generation, however
            // many times a webhook is delivered. (NULLs are distinct, so purchases
            // without a generation aren't affected.)
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance');
        });
    }
};
