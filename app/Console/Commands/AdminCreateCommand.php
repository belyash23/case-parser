<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class AdminCreateCommand extends Command
{
    protected $signature = 'admin:create
        {login : Administrator login}
        {--email= : Administrator email}
        {--name= : Display name}
        {--password= : Password; omit to enter it securely}';

    protected $description = 'Create or promote the single application administrator.';

    public function handle(): int
    {
        $login = Str::lower(trim((string) $this->argument('login')));
        $loginValidator = Validator::make(
            ['login' => $login],
            ['login' => ['required', 'string', 'min:3', 'max:64', 'regex:/\A[a-z0-9._-]+\z/']],
        );

        if ($loginValidator->fails()) {
            $this->error($loginValidator->errors()->first('login'));

            return self::FAILURE;
        }

        $userWithLogin = User::query()->where('login', $login)->first();
        $currentAdmin = User::query()->where('is_admin', true)->first();

        if ($userWithLogin instanceof User && $currentAdmin instanceof User && ! $userWithLogin->is($currentAdmin)) {
            $this->error('Another administrator already exists. Demote it before creating a replacement.');

            return self::FAILURE;
        }

        $user = $userWithLogin ?? $currentAdmin;
        $otherAdminExists = User::query()
            ->where('is_admin', true)
            ->when($user instanceof User, fn ($query) => $query->whereKeyNot($user->getKey()))
            ->exists();

        if ($otherAdminExists) {
            $this->error('Another administrator already exists. Demote it before creating a replacement.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) ($this->option('email')
            ?: $user?->email
            ?: text('Administrator email', required: true))));
        $emailValidator = Validator::make(
            ['email' => $email],
            ['email' => [
                'required',
                'email:rfc',
                'max:255',
                Rule::unique(User::class)->ignore($user?->id),
            ]],
        );

        if ($emailValidator->fails()) {
            $this->error($emailValidator->errors()->first('email'));

            return self::FAILURE;
        }

        $name = (string) ($this->option('name')
            ?: $user?->name
            ?: text('Administrator name', default: 'Administrator', required: true));
        $passwordValue = $this->option('password');
        $plainPassword = null;

        if (! $user instanceof User || (is_string($passwordValue) && $passwordValue !== '')) {
            $plainPassword = is_string($passwordValue) && $passwordValue !== ''
                ? $passwordValue
                : password('Administrator password', required: true, validate: ['password' => [Password::min(12)]]);
            $passwordValidator = Validator::make(
                ['password' => $plainPassword],
                ['password' => ['required', 'string', Password::min(12)]],
            );

            if ($passwordValidator->fails()) {
                $this->error($passwordValidator->errors()->first('password'));

                return self::FAILURE;
            }
        }

        if (! $user instanceof User) {
            $user = User::query()->create([
                'login' => $login,
                'name' => $name,
                'email' => $email,
                'password' => Hash::make((string) $plainPassword),
            ]);
        }

        $attributes = [
            'login' => $login,
            'name' => $name,
            'email' => $email,
            'is_admin' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ];

        if (is_string($plainPassword)) {
            $attributes['password'] = Hash::make($plainPassword);
        }

        $user->forceFill($attributes)->save();

        $this->info("Administrator {$login} is ready.");

        return self::SUCCESS;
    }
}
