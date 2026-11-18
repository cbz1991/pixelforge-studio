<?php

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates accounts from the command line', function () {
    $this->artisan('users:create', [
        'email' => 'Reviewer@Example.com',
        '--password' => 'secret-pass-123',
        '--credits' => 20,
        '--admin' => true,
    ])->assertSuccessful();

    $user = User::firstWhere('email', 'reviewer@example.com');

    expect($user->name)->toBe('reviewer')
        ->and($user->is_admin)->toBeTrue()
        ->and(Hash::check('secret-pass-123', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->balance)->toBe(20_000_000_000)
        ->and((int) CreditTransaction::where('user_id', $user->id)->sum('amount'))->toBe($user->balance);
});

it('generates a password when none is given', function () {
    $this->artisan('users:create', ['email' => 'demo@example.com'])->assertSuccessful();

    $user = User::firstWhere('email', 'demo@example.com');
    expect($user->is_admin)->toBeFalse()
        ->and($user->balance)->toBe(0)
        ->and(Hash::check('password', $user->password))->toBeFalse();
});

it('refuses duplicate or invalid emails', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('users:create', ['email' => 'taken@example.com'])->assertFailed();
    $this->artisan('users:create', ['email' => 'not-an-email'])->assertFailed();
});

it('has no public registration or password reset', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('password.request'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->get(route('login'))->assertOk()->assertDontSee('Sign up');
});

it('lets nobody change, secure or delete a shared account from the app', function (string $method, string $uri) {
    $this->actingAs(User::factory()->create())
        ->call($method, $uri)
        ->assertNotFound();
})->with([
    'profile page' => ['GET', '/settings/profile'],
    'delete account' => ['DELETE', '/settings/profile'],
    'change password' => ['PUT', '/settings/password'],
    'Fortify password update' => ['PUT', '/user/password'],
    'enable two-factor' => ['POST', '/user/two-factor-authentication'],
]);
