<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'family:create-admin {email} {--name= : Display name} {--locale=sk : sk, pl or en}';

    protected $description = 'Create the first family admin (refuses when an admin already exists)';

    public function handle(): int
    {
        if (User::where('is_admin', true)->exists()) {
            $this->error('An admin already exists.');

            return 1;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: Str::before($email, '@')));
        $locale = (string) $this->option('locale');

        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'locale' => $locale, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'locale' => ['required', 'in:sk,pl,en'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return 1;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill([
            'is_admin' => true,
            'locale' => $locale,
            'email_verified_at' => now(),
        ])->save();

        $this->info("Admin {$email} created.");

        return 0;
    }
}
