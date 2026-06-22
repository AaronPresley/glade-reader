<?php

namespace App\Domain\User\Commands;

use App\Domain\User\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

class PasswordReset extends Command
{
    protected $signature = 'password-reset';

    protected $description = 'Reset the password for the only user in the system';

    public function handle(): int
    {
        $userCount = User::query()->count();

        if ($userCount > 1) {
            $this->error('Password reset can only run when one user exists in the system.');

            return self::FAILURE;
        }

        $user = User::query()->first();

        if ($user === null) {
            $this->error('Password reset cannot run because no users exist in the system.');

            return self::FAILURE;
        }

        $this->line("Username: {$user->username}");

        $newPassword = password(
            label: 'New password',
            required: 'Password is required.',
        );

        password(
            label: 'Confirm new password',
            required: 'Password confirmation is required.',
            validate: fn (string $value): ?string => $value === $newPassword
                ? null
                : 'Passwords do not match.',
        );

        $user->forceFill([
            'password' => $newPassword,
        ])->save();

        $this->info('Password reset successfully.');

        return self::SUCCESS;
    }
}
