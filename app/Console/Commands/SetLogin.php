<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;

/**
 * Makes the login, or sets a new password for it. OneDOST has no sign-up
 * page and no "forgot password" email: whoever can run artisan on the
 * server is who may set the password.
 */
#[Signature('app:user {email : The email you log in with} {--name= : Your name, as the app shows it}')]
#[Description('Create the login, or set a new password for it')]
class SetLogin extends Command
{
    private const MIN_LENGTH = 10;

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error('That isn\'t an email address.');

            return self::FAILURE;
        }

        $user = User::firstWhere('email', $email);
        $password = password(
            label: $user ? "New password for {$email}" : "Password for {$email}",
            required: true,
            validate: fn (string $value) => mb_strlen($value) < self::MIN_LENGTH ? 'Use at least '.self::MIN_LENGTH.' characters.' : null,
            hint: 'At least '.self::MIN_LENGTH.' characters. A few words strung together are easy to remember and hard to guess.',
        );
        if (password(label: 'Type it again', required: true) !== $password) {
            $this->error('The passwords don\'t match. Nothing changed.');

            return self::FAILURE;
        }

        $user ??= new User(['email' => $email]);
        $user->fill([
            'name' => $this->option('name') ?: ($user->name ?? strstr($email, '@', true)),
            'password' => $password,
        ])->save();
        // A new password signs out anything still remembered with the old one.
        $user->setRememberToken(null);
        $user->save();

        $this->info($user->wasRecentlyCreated ? "Login made for {$email}." : "New password set for {$email}.");

        return self::SUCCESS;
    }
}
