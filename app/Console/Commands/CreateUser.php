<?php

namespace App\Console\Commands;

use App\Billing\CreditLedger;
use App\Billing\Nanodollars;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * There's no public sign-up: accounts are handed out, so they're created here.
 */
#[Signature('users:create
    {email : Email address to log in with}
    {--name= : Display name (defaults to the part of the email before the @)}
    {--password= : Password to use (a random one is generated and shown if omitted)}
    {--credits=0 : Whole US dollars of starting credit}
    {--admin : Give access to the spend report}')]
#[Description('Create an account (there is no public registration)')]
class CreateUser extends Command
{
    public function handle(CreditLedger $ledger): int
    {
        $email = Str::lower((string) $this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("{$email} is not a valid email address.");

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("A user with {$email} already exists.");

            return self::FAILURE;
        }

        $credits = (int) $this->option('credits');
        $password = (string) ($this->option('password') ?: Str::password(16, symbols: false));

        $user = new User([
            'name' => $this->option('name') ?: Str::before($email, '@'),
            'email' => $email,
            'password' => $password,
        ]);
        $user->forceFill(['email_verified_at' => now(), 'is_admin' => (bool) $this->option('admin')])->save();

        if ($credits > 0) {
            // Through the ledger, like any purchase, so the balance stays the sum of its rows.
            $ledger->purchase($user, $credits * Nanodollars::PER_DOLLAR);
        }

        $this->table(['Email', 'Password', 'Admin', 'Credits'], [[
            $user->email,
            $this->option('password') ? '(as given)' : $password,
            $user->is_admin ? 'yes' : 'no',
            Nanodollars::formatUsd($credits * Nanodollars::PER_DOLLAR),
        ]]);

        return self::SUCCESS;
    }
}
