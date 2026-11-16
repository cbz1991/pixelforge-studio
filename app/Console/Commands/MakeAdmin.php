<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:make-admin {email : The email the user registered with} {--revoke : Remove admin access instead}')]
#[Description('Give a user access to the admin spend report')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $user = User::firstWhere('email', $this->argument('email'));

        if ($user === null) {
            $this->error('No user with that email. Register first, then run this again.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();

        $this->info($user->is_admin ? "{$user->email} is now an admin." : "{$user->email} is no longer an admin.");

        return self::SUCCESS;
    }
}
